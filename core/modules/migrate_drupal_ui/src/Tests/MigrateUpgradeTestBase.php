<?php

/**
 * @file
 * Contains \Drupal\migrate_drupal_ui\Tests\MigrateUpgradeTestBase.
 */

namespace Drupal\migrate_drupal_ui\Tests;

use Drupal\Core\Database\Database;
use Drupal\simpletest\WebTestBase;

/**
 * Provides a base class for testing migration upgrades in the UI.
 */
abstract class MigrateUpgradeTestBase extends WebTestBase {

  /**
   * Use the Standard profile to test help implementations of many core modules.
   */
  protected $profile = 'standard';

  /**
   * The source database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $sourceDatabase;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['migrate_drupal_ui'];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->createMigrationConnection();
    $this->sourceDatabase = Database::getConnection('default', 'migrate_drupal_ui');

    // Create and log in as user 1. Migrations in the UI can only be performed
    // as user 1 once https://www.drupal.org/node/2675066 lands.
    $this->drupalLogin($this->rootUser);
  }

  /**
   * Loads a database fixture into the source database connection.
   *
   * @param string $path
   *   Path to the dump file.
   */
  protected function loadFixture($path) {
    $default_db = Database::getConnection()->getKey();
    Database::setActiveConnection($this->sourceDatabase->getKey());

    if (substr($path, -3) == '.gz') {
      $path = 'compress.zlib://' . $path;
    }
    require $path;

    Database::setActiveConnection($default_db);
  }

  /**
   * Changes the database connection to the prefixed one.
   *
   * @todo Remove when we don't use global. https://www.drupal.org/node/2552791
   */
  protected function createMigrationConnection() {
    $connection_info = Database::getConnectionInfo('default')['default'];
    if ($connection_info['driver'] === 'sqlite') {
      // Create database file in the test site's public file directory so that
      // \Drupal\simpletest\TestBase::restoreEnvironment() will delete this once
      // the test is complete.
      $file = $this->publicFilesDirectory . '/' . $this->testId . '-migrate.db.sqlite';
      touch($file);
      $connection_info['database'] = $file;
      $connection_info['prefix'] = '';
    }
    else {
      $prefix = is_array($connection_info['prefix']) ? $connection_info['prefix']['default'] : $connection_info['prefix'];
      // Simpletest uses fixed length prefixes. Create a new prefix for the
      // source database. Adding to the end of the prefix ensures that
      // \Drupal\simpletest\TestBase::restoreEnvironment() will remove the
      // additional tables.
      $connection_info['prefix'] = $prefix . '0';
    }

    Database::addConnectionInfo('migrate_drupal_ui', 'default', $connection_info);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown() {
    Database::removeConnection('migrate_drupal_ui');
    parent::tearDown();
  }

  /**
   * Executes all steps of migrations upgrade.
   */
  protected function testMigrateUpgrade() {
    $connection_options = $this->sourceDatabase->getConnectionOptions();
    $this->drupalGet('/upgrade');
    $this->assertText('Upgrade a Drupal site by importing it into a clean and empty new install of Drupal 8. You will lose any existing configuration once you import your site into it. See the upgrading handbook for more detailed information.');

    $this->drupalPostForm(NULL, [], t('Continue'));
    $this->assertText('Provide credentials for the database of the Drupal site you want to upgrade.');
    $this->assertFieldByName('mysql[host]');

    $driver = $connection_options['driver'];
    $connection_options['prefix'] = $connection_options['prefix']['default'];

    // Use the driver connection form to get the correct options out of the
    // database settings. This supports all of the databases we test against.
    $drivers = drupal_get_database_types();
    $form = $drivers[$driver]->getFormOptions($connection_options);
    $connection_options = array_intersect_key($connection_options, $form + $form['advanced_options']);
    $edits = $this->translatePostValues([
      'driver' => $driver,
      $driver => $connection_options,
      'source_base_path' => $this->getSourceBasePath(),
    ]);

    $this->drupalPostForm(NULL, $edits, t('Review upgrade'));
    $this->assertResponse(200);
    $this->assertText('Are you sure?');
    $this->drupalPostForm(NULL, [], t('Perform upgrade'));
    $this->assertText(t('Congratulations, you upgraded Drupal!'));

    // Have to reset all the statics after migration to ensure entities are
    // loadable.
    $this->resetAll();

    $expected_counts = $this->getEntityCounts();
    foreach (array_keys(\Drupal::entityTypeManager()->getDefinitions()) as $entity_type) {
      $real_count = count(\Drupal::entityTypeManager()->getStorage($entity_type)->loadMultiple());
      $expected_count = isset($expected_counts[$entity_type]) ? $expected_counts[$entity_type] : 0;
      $this->assertEqual($expected_count, $real_count, "Found $real_count $entity_type entities, expected $expected_count.");
    }
  }

  /**
   * Gets the source base path for the concrete test.
   *
   * @return string
   *   The source base path.
   */
  abstract protected function getSourceBasePath();

  /**
   * Gets the expected number of entities per entity type after migration.
   *
   * @return int[]
   *   An array of expected counts keyed by entity type ID.
   */
  abstract protected function getEntityCounts();

}
