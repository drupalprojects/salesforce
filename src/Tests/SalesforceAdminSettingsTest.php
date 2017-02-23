<?php

namespace Drupal\salesforce\Tests;

use Drupal\simpletest\WebTestBase;

/**
 * Tests for webform entity.
 *
 * @group Webform
 */
class SalesforceAdminSettingsTest extends WebTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'salesforce',
    'user',
    'salesforce_test_rest_client'
  ];

  protected $normalUser;
  protected $adminSalesforceUser;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    // Admin salesforce user.
    $this->adminSalesforceUser = $this->drupalCreateUser(['administer salesforce']);
  }

  /**
   * Tests webform admin settings.
   */
  public function testAdminSettings() {
    return;
    global $base_path, $base_url;

    $this->drupalLogin($this->adminSalesforceUser);

    /* Salesforce Settings */
    $this->assertNull(\Drupal::state()->get('salesforce.consumer_key'));
    $this->assertNull(\Drupal::state()->get('salesforce.consumer_secret'));
    $this->assertNull(\Drupal::state()->get('salesforce.login_url'));

    $key = $this->randomMachineName();
    $secret = $this->randomMachineName();
    $url = 'https://login.salesforce.com';
    $post = [
        'consumer_key' => $key,
        'consumer_secret' => $secret,
        'login_url' => $url,
      ];
    $this->drupalPostForm('admin/config/salesforce/authorize', $post, t('Save configuration'));

    $newurl = parse_url($this->getUrl());
    
    $query = [];
    parse_str($newurl['query'], $query);

    // Check the redirect URL matches expectations:
    $this->assertEqual($key, $query['client_id']);
    $this->assertEqual('code', $query['response_type']);
    $this->assertEqual(str_replace('http://', 'https://', $base_url) . '/salesforce/oauth_callback', $query['redirect_uri']);

    // Check that our state was updated:
    $this->assertEqual($key, \Drupal::state()->get('salesforce.consumer_key'));
    $this->assertEqual($secret, \Drupal::state()->get('salesforce.consumer_secret'));
    $this->assertEqual($url, \Drupal::state()->get('salesforce.login_url'));

  }

  public function testOauthCallback() {
    $this->drupalLogin($this->adminSalesforceUser);

    $code = $this->randomMachineName();

    // Prevent redirects, and do HEAD only, otherwise we're catching errors. If
    // the oauth callback gets as far as issuing a redirect, then we've
    // succeeded as far as this test is concerned.
    $this->maximumRedirects = 0;
    $this->drupalHead('salesforce/oauth_callback', ['query' => ['code' => $code]]);

    // Confirm that oauth_callback redirected properly
    $this->assertEqual('/admin/config/salesforce/authorize', $this->drupalGetHeader('location'));
  }

}
