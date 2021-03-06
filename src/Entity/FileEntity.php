<?php

/**
 * @file
 * Contains \Drupal\file_entity\Entity\FileEntity.
 */

namespace Drupal\file_entity\Entity;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;

/**
 * Replace for the core file entity class.
 */
class FileEntity extends File {

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface $storage, array &$values) {
    parent::preCreate($storage, $values);
    $values += array(
      'type' => FILE_TYPE_NONE,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(array $values, $entity_type, $bundle = FALSE, $translations = array()) {
    if (!$bundle) {
      $values['type'] = FILE_TYPE_NONE;
      $bundle = FILE_TYPE_NONE;
    }

    parent::__construct($values, $entity_type, $bundle, $translations);
  }


  /**
   * {@inheritdoc}
   */
  public function url($rel = 'canonical', $options = array()) {
    // While self::urlInfo() will throw an exception if the entity is new,
    // the expected result for a URL is always a string.
    if ($this->isNew() || !$this->hasLinkTemplate($rel)) {
      return '';
    }

    $uri = $this->urlInfo($rel);
    $options += $uri->getOptions();
    $uri->setOptions($options);
    return $uri->toString();
  }

  /**
   * {@inheritdoc}
   */
  public function postCreate(EntityStorageInterface $storage) {
    parent::postCreate($storage);

    // Update the bundle.
    if ($this->bundle() === FILE_TYPE_NONE) {
      $this->updateBundle();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    if (file_exists($this->getFileUri())) {
      $this->setSize(filesize($this->getFileUri()));
    }

    $this->setMimeType(\Drupal::service('file.mime_type.guesser')->guess($this->getFileUri()));

    // Update the bundle.
    if ($this->bundle() === FILE_TYPE_NONE) {
      $this->updateBundle();
    }
    // \Drupal\Core\Entity\ContentEntityStorageBase::hasFieldChanged() expects
    // that the original entity has the same fields. Update the bundle if it was
    // changed.
    if (!empty($this->original) && $this->bundle() != $this->original->bundle()) {
      $this->original->get('type')->target_id = $this->bundle();
      $this->original->fieldDefinitions = NULL;
      $this->original->typedData = NULL;
      $this->original->entityKeys['bundle'] = $this->bundle();
    }

    // Fetch image dimensions.
    $this->fetchImageDimensions();
  }

  /**
   * Fetch the dimensions of an image and store them in the file metadata array.
   */
  protected function fetchImageDimensions() {
    // Prevent PHP notices when trying to read empty files.
    // @see http://drupal.org/node/681042
    if (!$this->getSize()) {
      return;
    }

    // Do not bother proceeding if this file does not have an image mime type.
    if ($this->getMimeTypeType() != 'image') {
      return;
    }

    // We have a non-empty image file.
    $image = \Drupal::service('image.factory')->get($this->getFileUri());
    if ($image) {
      $this->metadata['width'] = $image->getWidth();
      $this->metadata['height'] = $image->getHeight();
    }
    else {
      // Fallback to NULL values.
      $this->metadata['width'] = NULL;
      $this->metadata['height'] = NULL;
    }
  }

  /**
   * Returns the first part of the mimetype of the file.
   *
   * @return string
   *   The mimetype.
   */
  public function getMimeTypeType() {
    list($type, $subtype) = explode('/', $this->getMimeType(), 2);
    return $type;
  }

  /**
   * Implements hook_file_insert().
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE) {
    parent::postSave($storage, $update);
    // Save file metadata.
    if (!empty($this->metadata)) {
    if ($update) {
      db_delete('file_metadata')->condition('fid', $this->id())->execute();
    }
      $query = db_insert('file_metadata')->fields(array('fid', 'name', 'value'));
      foreach ($this->metadata as $name => $value) {
        $query->values(array(
          'fid' => $this->id(),
          'name' => $name,
          'value' => serialize($value),
        ));
      }
      $query->execute();
    }

    if ($update) {
      if (\Drupal::moduleHandler()->moduleExists('image') && $this->getMimeTypeType() == 'image' && $this->getSize()) {
        // If the image dimensions have changed, update any image field references
        // to this file and flush image style derivatives.
        if (isset($this->original->metadata['width']) && ($this->metadata['width'] != $this->original->metadata['width'] || $this->metadata['height'] != $this->original->metadata['height'])) {
          $this->updateImageFieldDimensions();
        }

        // Flush image style derivatives whenever an image is updated.
        image_path_flush($this->getFileUri());
      }
    }

  }

  /**
   * Updates the image dimensions stored in any image fields for a file.
   *
   * @see http://drupal.org/node/1448124
   */
  protected function updateImageFieldDimensions() {
    // Prevent PHP notices when trying to read empty files.
    // @see http://drupal.org/node/681042
    if (!$this->getSize()) {
      return;
    }

    // Do not bother proceeding if this file does not have an image mime type.
    if ($this->getMimeTypeType() != 'image') {
      return;
    }

    // Find all image field enabled on the site.
    $image_fields = \Drupal::service('entity_field.manager')->getFieldMapByFieldType('image');
    foreach ($image_fields as $entity_type_id => $field_names) {
      foreach (array_keys($field_names) as $image_field) {
        $ids = \Drupal::entityQuery($entity_type_id)
          ->condition($image_field . '.fid', $this->id())
          ->execute();

        $entities = \Drupal::entityTypeManager()
          ->getStorage($entity_type_id)
          ->load($ids);
          /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
        foreach ($entities as $entity) {
          foreach (array_keys($entity->getTranslationLanguages()) as $langcode) {
            $translation = $entity->getTranslation($langcode);
            foreach ($translation->$image_field as $item) {
              if ($item->target_id == $this->id()) {
                $item->width  = $this->metadata['width'];
                $item->height  = $this->metadata['height'];
              }
            }
          }

          // Save the updated field column values.
          $entity->save();
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function preDelete(EntityStorageInterface $storage, array $entities) {
    parent::preDelete($storage, $entities);
    // Remove file metadata.
    db_delete('file_metadata')
      ->condition('fid', array_keys($entities), 'IN')
      ->execute();
  }

  /**
   * Updates the file bundle.
   */
  public function updateBundle($type = NULL) {
    if (!$type) {
      $type = file_get_type($this);

      if (!$type) {
        return;
      }
    }

    // Update the type field.
    $this->get('type')->target_id = $type;
    // Clear the field definitions, so that they will be fetched for the new bundle.
    $this->fieldDefinitions = NULL;
    $this->typedData = NULL;
    // Update the entity keys cache.
    $this->entityKeys['bundle'] = $type;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['type'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('File type'))
      ->setDescription(t('The type of the file.'))
      ->setSetting('target_type', 'file_type');

    $fields['filename']
      ->setDisplayOptions('view', array(
        'type' => 'string',
        'label' => 'hidden',
        'weight' => -5,
      ))
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('form', array(
        'type' => 'string_textfield',
        'weight' => -5,
      ))
      ->setDisplayConfigurable('form', TRUE);

    $fields['uri']
      ->setDisplayOptions('view', array(
        'type' => 'file_image',
        'label' => 'hidden',
        'weight' => -5,
      ))
      ->setDisplayConfigurable('view', TRUE);

    $fields['uid']->setDisplayOptions('view', array(
      'type' => 'uri_link',
      'weight' => 1,
    ))
      ->setDisplayOptions('form', array(
        'type' => 'entity_reference_autocomplete',
        'weight' => -1,
        'settings' => array(
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'autocomplete_type' => 'tags',
          'placeholder' => '',
        )
      ));

    $fields['filemime']->setDisplayOptions('view', array(
      'type' => 'string',
      'weight' => 2,
    ));
    $fields['filesize']->setDisplayOptions('view', array(
      'type' => 'file_size',
      'weight' => 3,
    ));

    return $fields;
  }

  /**
   * Check if a file entity is readable or not.
   *
   * @return bool
   *   TRUE if the file is using a readable stream wrapper, or FALSE otherwise.
   */
  function isReadable() {
    $scheme = file_uri_scheme($this->getFileUri());
    $wrappers = \Drupal::service('stream_wrapper_manager')->getWrappers(StreamWrapperInterface::READ);
    return !empty($wrappers[$scheme]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    // Assume that files are only embedded in other entities and don't have
    // their own cache tags.
    // @todo Make this configurable.
    return [];
  }

  /**
   * Invalidates an entity's cache tags upon save.
   *
   * @param bool $update
   *   TRUE if the entity has been updated, or FALSE if it has been inserted.
   */
  protected function invalidateTagsOnSave($update) {
    // An entity was created or updated: invalidate its list cache tags. (An
    // updated entity may start to appear in a listing because it now meets that
    // listing's filtering requirements. A newly created entity may start to
    // appear in listings because it did not exist before.)
    $tags = $this->getEntityType()->getListCacheTags();
    if ($update) {
      // Files don't have their own cache tags, instead, we invalidate cache
      // tags of entities that use that file.
      foreach (\Drupal::service('file.usage')->listUsage($this) as $module => $module_references) {
        foreach ($module_references as $type => $ids) {
          if ($this->entityManager()->hasDefinition($type)) {
            $tags = Cache::mergeTags($tags, Cache::buildTags($type, array_keys($ids)));
          }
        }
      }
    }
    Cache::invalidateTags($tags);
  }

  /**
   * Invalidates an entity's cache tags upon delete.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Entity\EntityInterface[] $entities
   *   An array of entities.
   */
  protected static function invalidateTagsOnDelete(EntityTypeInterface $entity_type, array $entities) {
    $tags = $entity_type->getListCacheTags();
    // We only invalidate cache tags of entities using the file. If a file is
    // deleted, we assume that it is no longer used.
    Cache::invalidateTags($tags);
  }

}
