<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * @group maniphest
 */
final class ManiphestTask extends ManiphestDAO {

  protected $phid;
  protected $authorPHID;
  protected $ownerPHID;
  protected $ccPHIDs = array();

  protected $status;
  protected $priority;

  protected $title;
  protected $description;
  protected $originalEmailSource;
  protected $mailKey;

  protected $attached = array();
  protected $projectPHIDs = array();
  private $projectsNeedUpdate;
  private $subscribersNeedUpdate;

  protected $ownerOrdering;

  private $auxiliaryAttributes;
  private $auxiliaryDirty = array();

  public function getConfiguration() {
    return array(
      self::CONFIG_AUX_PHID => true,
      self::CONFIG_SERIALIZATION => array(
        'ccPHIDs' => self::SERIALIZATION_JSON,
        'attached' => self::SERIALIZATION_JSON,
        'projectPHIDs' => self::SERIALIZATION_JSON,
      ),
    ) + parent::getConfiguration();
  }

  public function getAttachedPHIDs($type) {
    return array_keys(idx($this->attached, $type, array()));
  }

  public function generatePHID() {
    return PhabricatorPHID::generateNewPHID(
      PhabricatorPHIDConstants::PHID_TYPE_TASK);
  }

  public function getCCPHIDs() {
    return array_values(nonempty($this->ccPHIDs, array()));
  }

  public function setProjectPHIDs(array $phids) {
    $this->projectPHIDs = array_values($phids);
    $this->projectsNeedUpdate = true;
    return $this;
  }

  public function getProjectPHIDs() {
    return array_values(nonempty($this->projectPHIDs, array()));
  }

  public function setCCPHIDs(array $phids) {
    $this->ccPHIDs = array_values($phids);
    $this->subscribersNeedUpdate = true;
    return $this;
  }

  public function setOwnerPHID($phid) {
    $this->ownerPHID = $phid;
    $this->subscribersNeedUpdate = true;
    return $this;
  }

  public function getAuxiliaryAttribute($key, $default = null) {
    if ($this->auxiliaryAttributes === null) {
      throw new Exception("Attach auxiliary attributes before getting them!");
    }
    return idx($this->auxiliaryAttributes, $key, $default);
  }

  public function setAuxiliaryAttribute($key, $val) {
    if ($this->auxiliaryAttributes === null) {
      throw new Exception("Attach auxiliary attributes before setting them!");
    }
    $this->auxiliaryAttributes[$key] = $val;
    $this->auxiliaryDirty[$key] = true;
    return $this;
  }

  public function attachAuxiliaryAttributes(array $attrs) {
    if ($this->auxiliaryDirty) {
      throw new Exception(
        "This object has dirty attributes, you can not attach new attributes ".
        "without writing or discarding the dirty attributes.");
    }
    $this->auxiliaryAttributes = $attrs;
    return $this;
  }

  public function loadAndAttachAuxiliaryAttributes() {
    if (!$this->getPHID()) {
      $this->auxiliaryAttributes = array();
      return;
    }

    $storage = id(new ManiphestTaskAuxiliaryStorage())->loadAllWhere(
      'taskPHID = %s',
      $this->getPHID());

    $this->auxiliaryAttributes = mpull($storage, 'getValue', 'getName');

    return $this;
  }


  public function save() {
    if (!$this->mailKey) {
      $this->mailKey = Filesystem::readRandomCharacters(20);
    }

    $result = parent::save();

    if ($this->projectsNeedUpdate) {
      // If we've changed the project PHIDs for this task, update the link
      // table.
      ManiphestTaskProject::updateTaskProjects($this);
      $this->projectsNeedUpdate = false;
    }

    if ($this->subscribersNeedUpdate) {
      // If we've changed the subscriber PHIDs for this task, update the link
      // table.
      ManiphestTaskSubscriber::updateTaskSubscribers($this);
      $this->subscribersNeedUpdate = false;
    }

    if ($this->auxiliaryDirty) {
      $this->writeAuxiliaryUpdates();
      $this->auxiliaryDirty = array();
    }

    return $result;
  }

  private function writeAuxiliaryUpdates() {
    $table = new ManiphestTaskAuxiliaryStorage();
    $conn_w = $table->establishConnection('w');
    $update = array();
    $remove = array();

    foreach ($this->auxiliaryDirty as $key => $dirty) {
      $value = $this->getAuxiliaryAttribute($key);
      if ($value === null) {
        $remove[$key] = true;
      } else {
        $update[$key] = $value;
      }
    }

    if ($remove) {
      queryfx(
        $conn_w,
        'DELETE FROM %T WHERE taskPHID = %s AND name IN (%Ls)',
        $table->getTableName(),
        $this->getPHID(),
        array_keys($remove));
    }

    if ($update) {
      $sql = array();
      foreach ($update as $key => $val) {
        $sql[] = qsprintf(
          $conn_w,
          '(%s, %s, %s)',
          $this->getPHID(),
          $key,
          $val);
      }
      queryfx(
        $conn_w,
        'INSERT INTO %T (taskPHID, name, value) VALUES %Q
          ON DUPLICATE KEY UPDATE value = VALUES(value)',
        $table->getTableName(),
        implode(', ', $sql));
    }
  }

}
