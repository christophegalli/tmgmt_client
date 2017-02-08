<?php


namespace Drupal\Core\Tests\tmgmt_client\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\tmgmt\Entity\Translator;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt\RemoteMappingInterface;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\tmgmt\Entity\Job;
use Drupal\tmgmt\Entity\JobItem;
use Drupal\tmgmt_server\Entity\TMGMTServerClient;
use Drupal\tmgmt_server\Entity\TMGMTServerRemoteSource;


/**
 * Class ClientTest.
 *
 * @group tmgmt_client
 */
class ClientTest extends BrowserTestBase    {
  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = [
    'config',
    'tmgmt',
    'tmgmt_content',
    'tmgmt_client',
    'node',
    'tmgmt_server',
    'tmgmt_local',
    'tmgmt_test',
    'language',
    'content_translation',
    'tmgmt_language_combination',
  ];

  /**
   * @var TMGMTServerClient
   */
  public $remote_client;

  public function setUp() {
    parent::setUp();

    // Add second language 'german' to the site.
    $language = ConfigurableLanguage::createFromLangcode('de');
    $language->save();

    // Create clint on the server side, to be targeted.
    $this->remote_client = TMGMTServerClient::create([
      'name' => 'test client',
      'description' => 'used to test the client',
      'url' => 'translator',
    ]);
    $this->remote_client->setKeys();
    $this->remote_client->save();

  }

  public function testTranslation() {

    global  $base_url;
    $user = $this->drupalCreateUser([
      'administer tmgmt',
      'view published tmgmt server client entities',
      'provide translation services',
    ]);

    // Add the skills necessary to the local translator.
    $user->tmgmt_translation_skills[] = array(
      'language_from' => 'en',
      'language_to' => 'de',
    );
    $user->save();
    $this->drupalLogin($user);

    \Drupal::configFactory()->getEditable('tmgmt_server.settings')
      ->set('default_translator', 'test_translator')->save();
    $edit = [
      'label' => 'Test Client Provider',
      'description' => 'Used for Testing purposes',
      'settings[remote_url]' => $base_url,
      'settings[client_id]' => $this->remote_client->getClientId(),
      'settings[client_secret]' => $this->remote_client->getClientSecret(),
      'remote_languages_mappings[en]' => 'en',
      'remote_languages_mappings[de]' => 'de-ch',
    ];
    $this->drupalPostForm('admin/tmgmt/translators/manage/client', $edit, 'Connect');
    $this->assertSession()->pageTextContains('Successfully connected!');

    $this->drupalPostForm('admin/tmgmt/translators/manage/client', $edit, 'Save');
    $this->assertSession()->pageTextContains('Test Client Provider configuration has been updated.');

    \Drupal::configFactory()->getEditable('tmgmt_server.settings')
      ->set('default_translator', 'test_translator')->save();

    $test_translator = Translator::load('test_translator');
    $test_translator->setAutoAccept(FALSE);
    $test_translator->save();

    // Prepare node.
    $node = $this->createTestNode();

    // Create the job.
    $job = Job::create([
      'label' => 'Test Job One',
      'uid' => 1,
      'source_language' => 'en',
      'target_language' => 'de',
      'translator' => 'client',
    ]);

    // Add the node to the job as item.
    $item = $job->addItem('content', 'node', $node->id());

    // Request translation.
    $edit = ['settings[job_comment]' => 'test comment'];
    $this->drupalPostForm('admin/tmgmt/jobs/' . $job->id(), $edit, 'Submit to provider');

    $this->assertSession()->pageTextContains('The translation job has been submitted.');

    // Find the corresponding remote job via the mapping.
    $remote_mapping = $job->getRemoteMappings();
    $this->assertEquals(count($remote_mapping), 1);

    // Find the remote_item via remote mapping.
    $remote_map = array_shift($remote_mapping);
    $remote_item_id = $remote_map->getRemoteIdentifier1();
    $remote_item = JobItem::load($remote_item_id);

    // Complete the remote translation, the job should come back to the client.
    $remote_item->acceptTranslation();

    // Reload the JobItem from DB.
    $item = JobItem::load($item->id());

    // Does it need review?
    $this->assertEquals($item->getstate(), JobItemInterface::STATE_REVIEW);

    // Complete the translation.
    $this->drupalGet('admin/tmgmt/items/' . $item->id());
    $this->assertSession()->pageTextContains('de(de-ch): Test node title');
    $this->assertSession()->pageTextContains('de(de-ch): Test node body');
    $this->assertSession()->pageTextContains('de(de-ch): Text for test field');

    $this->drupalPostForm('admin/tmgmt/items/' . $item->id(), [], 'Save as completed');
    $confirmation = 'The translation for ' . $node->getTitle() . ' has been accepted as de(de-ch): ' . $node->getTitle();
    $this->assertSession()->pageTextContains($confirmation);

    // Test the pull functionality.
    // Create the job.
    $job = Job::create([
      'label' => 'Test Job Two',
      'uid' => 1,
      'source_language' => 'en',
      'target_language' => 'de',
      'translator' => 'client',
    ]);

    // Add the node to the job as item. Set the callback to inexistent url.
    $item = $job->addItem('content', 'node', $node->id());
    // Request translation.
    $edit = ['settings[job_comment]' => 'test comment'];
    $this->drupalPostForm('admin/tmgmt/jobs/' . $job->id(), $edit, 'Submit to provider');
    $this->assertSession()->pageTextContains('The translation job has been submitted.');

    // Find the corresponding remote job via the mapping.
    $remote_mapping = $job->getRemoteMappings();
    $this->assertEquals(count($remote_mapping), 1);

    // Find the remote_item via remote mapping.
    $remote_map = array_shift($remote_mapping);
    $remote_item_id = $remote_map->getRemoteIdentifier1();
    $remote_item = JobItem::load($remote_item_id);

    // Change to callback to dummy.
    $remote_source = TMGMTServerRemoteSource::load($remote_item->getItemId());
    $remote_source->callback = 'http://dummy.ch';
    $remote_source->save();
    // Complete the remote translation, the job should come back to the client.
    $remote_item->acceptTranslation();

    // Make sure that the job item is not yet translated.
    $item = JobItem::load($item->id());
    $this->assertEquals($item->getState(), JobItemInterface::STATE_ACTIVE);

    // Pull translation.
    $this->drupalPostForm('admin/tmgmt/jobs/' . $job->id(), [], 'Pull translations from remote server');
    \Drupal::entityTypemanager()->getStorage('tmgmt_job_item')->resetCache([$item->id()]);
    $item = JobItem::load($item->id());
    $this->assertEquals($item->getState(), JobItemInterface::STATE_REVIEW);

    // Complete the translation and check the result.
    $this->drupalGet('admin/tmgmt/items/' . $item->id());
    $this->assertSession()->pageTextContains('de(de-ch): Test node title');
    $this->assertSession()->pageTextContains('de(de-ch): Test node body');
    $this->assertSession()->pageTextContains('de(de-ch): Text for test field');

    $this->drupalPostForm('admin/tmgmt/items/' . $item->id(), [], 'Save as completed');
    $confirmation = 'The translation for ' . $node->getTitle() . ' has been accepted as de(de-ch): ' . $node->getTitle();
    $this->assertSession()->pageTextContains($confirmation);  }

  /**
   * Helper function to define and create node.
   *
   * @return Node
   *   The created node.
   */
  protected function createTestNode() {
    // Create a content type and make it translatable.
    $this->drupalCreateContentType(array(
      'type' => 'article',
      'name' => 'Article',
    ));
    $content_translation_manager = \Drupal::service('content_translation.manager');
    $content_translation_manager->setEnabled('node', 'article', TRUE);

    // Create a field.
    $field_storage = FieldStorageConfig::create(array(
      'field_name' => 'field_test',
      'entity_type' => 'node',
      'type' => 'text',
      'cardinality' => 1,
      'translatable' => TRUE,
    ));
    $field_storage->save();

    // Create an instance of the previously created field.
    $field = FieldConfig::create(array(
      'field_name' => 'field_test',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Test field label',
      'description' => 'One field for testing',
      'widget' => array(
        'type' => 'text_textfield',
        'label' => 'Test field widget',
      ),
    ));
    $field->save();

    $node = Node::create([
      'type' => 'article',
      'title' => 'Test node title',
      'body' => 'Test node body',
      'field_test' => 'Text for test field',
    ]);
    $node->save();

    return $node;
  }

}
