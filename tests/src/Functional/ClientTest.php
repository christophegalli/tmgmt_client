<?php


namespace Drupal\Core\Tests\tmgmt_client\Functional;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\BrowserTestBase;
use Drupal\tmgmt\Tests\EntityTestBase;
use Drupal\tmgmt\Tests\TMGMTTestBase;


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
  public static $modules = ['tmgmt','tmgmt_client', 'tmgmt_server','language'];

  public function setUp() {
    parent::setUp();

    // Add second language 'german' to the site.
    $language = ConfigurableLanguage::createFromLangcode('de');
    $language->save();


  }

  public function testClientSetup() {

    $account = $this->drupalCreateUser(['administer languages']);
    $this->drupalLogin($account);

    $this->drupalGet('admin/config/regional/language');
    //$this->assertSession()->pageTextContains('German');
  }
}
