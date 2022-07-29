<?php
declare( strict_types=1 );

require_once __DIR__ . '/../../facebook-commerce.php';

use Automattic\WooCommerce\GoogleListingsAndAds\Product\ProductHelper;
use SkyVerge\WooCommerce\Facebook\Admin;
use SkyVerge\WooCommerce\Facebook\Admin\Products as AdminProducts;
use SkyVerge\WooCommerce\Facebook\Admin\Enhanced_Catalog_Attribute_Fields;
use SkyVerge\WooCommerce\Facebook\Handlers\Connection;
use SkyVerge\WooCommerce\Facebook\Products;
use SkyVerge\WooCommerce\Facebook\ProductSync\ProductValidator;

/**
 * Unit tests for Facebook Graph API calls.
 */
class WCFacebookCommerceIntegrationTest extends WP_UnitTestCase {

	/** @var WC_Facebookcommerce */
	private $facebook_for_woocommerce;

	/** @var Connection */
	private $connection_handler;

	/** @var WC_Facebookcommerce_Integration */
	private $integration;

	/**
	 * Default plugin options.
	 *
	 * @var array
	 */
	private static $default_options = [
		WC_Facebookcommerce_Pixel::PIXEL_ID_KEY     => '0',
		WC_Facebookcommerce_Pixel::USE_PII_KEY      => true,
		WC_Facebookcommerce_Pixel::USE_S2S_KEY      => false,
		WC_Facebookcommerce_Pixel::ACCESS_TOKEN_KEY => '',
	];

	/**
	 * Runs before each test is executed.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->facebook_for_woocommerce = $this->createMock( WC_Facebookcommerce::class );
		$this->connection_handler       = $this->createMock( Connection::class );
		$this->facebook_for_woocommerce->method( 'get_connection_handler' )
			->willReturn( $this->connection_handler );

		$this->integration = new WC_Facebookcommerce_Integration( $this->facebook_for_woocommerce );

		/* Making sure no options are set before the test. */
		delete_option( WC_Facebookcommerce_Pixel::SETTINGS_KEY );
		delete_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PIXEL_ID );
	}

	/**
	 * Tests init pixel method does nothing for non admin users.
	 *
	 * @return void
	 */
	public function test_init_pixel_for_non_admin_user_must_do_nothing() {
		$this->assertFalse( is_admin(), 'Current user must not be an admin user.' );
		$this->assertFalse( $this->integration->init_pixel() );
	}

	/**
	 * Tests init pixel inits with default options.
	 *
	 * @return void
	 */
	public function test_init_pixel_for_admin_user_must_init_pixel_default_options() {
		/* Setting up Admin user. */
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		set_current_screen( 'edit-post' );

		$this->assertTrue( is_admin(), 'Current user must be an admin user.' );
		$this->assertTrue( $this->integration->init_pixel() );
		$this->assertEquals(
			self::$default_options,
			get_option( WC_Facebookcommerce_Pixel::SETTINGS_KEY )
		);
	}

	/**
	 * Tests migrating some setting from wc settings to wp options when init.
	 *
	 * @return void
	 */
	public function test_init_pixel_for_admin_user_must_init_pixel_migrating_wc_pixel_settings_to_wp_options() {
		/* Setting up Admin User. */
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		set_current_screen( 'edit-post' );

		/* Setting up WC Facebook pixel id. */
		add_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PIXEL_ID, '112233445566778899' );

		/* Setting up initial options. */
		add_option(
			WC_Facebookcommerce_Pixel::SETTINGS_KEY,
			self::$default_options
		);

		$this->assertTrue( is_admin(), 'Current user must be an admin user.' );
		$this->assertFalse( has_filter( 'wc_facebook_pixel_id' ) );
		$this->assertTrue( $this->integration->init_pixel() );
		$this->assertEquals(
			[
				WC_Facebookcommerce_Pixel::PIXEL_ID_KEY => '112233445566778899',
				WC_Facebookcommerce_Pixel::USE_PII_KEY  => true,
				WC_Facebookcommerce_Pixel::USE_S2S_KEY  => false,
				WC_Facebookcommerce_Pixel::ACCESS_TOKEN_KEY => '',
			],
			get_option( WC_Facebookcommerce_Pixel::SETTINGS_KEY )
		);
	}

	/**
	 * Tests init pixel with filter set.
	 *
	 * @return void
	 */
	public function test_init_pixel_for_admin_user_must_init_pixel_overwrites_pixel_id_with_filter() {
		/* Setting up Admin User. */
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		set_current_screen( 'edit-post' );

		/* Setting up initial options. */
		add_option(
			WC_Facebookcommerce_Pixel::SETTINGS_KEY,
			self::$default_options
		);

		add_filter(
			'wc_facebook_pixel_id',
			function ( $wc_facebook_pixel_id ) {
				return '998877665544332211';
			}
		);

		$this->assertTrue( is_admin(), 'Current user must be an admin user.' );
		$this->assertTrue( has_filter( 'wc_facebook_pixel_id' ) );
		$this->assertTrue( $this->integration->init_pixel() );
		$this->assertEquals(
			[
				WC_Facebookcommerce_Pixel::PIXEL_ID_KEY => '998877665544332211',
				WC_Facebookcommerce_Pixel::USE_PII_KEY  => true,
				WC_Facebookcommerce_Pixel::USE_S2S_KEY  => false,
				WC_Facebookcommerce_Pixel::ACCESS_TOKEN_KEY => '',
			],
			get_option( WC_Facebookcommerce_Pixel::SETTINGS_KEY )
		);
	}

	/**
	 * Tests init pixel for admin user uses filter to overwrite use pii settings.
	 *
	 * @return void
	 */
	public function test_init_pixel_for_admin_user_must_init_pixel_overwrites_use_pii_with_filter() {
		/* Setting up Admin User. */
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		set_current_screen( 'edit-post' );

		/* Setting up initial options. */
		add_option(
			WC_Facebookcommerce_Pixel::SETTINGS_KEY,
			self::$default_options
		);

		add_filter(
			'wc_facebook_is_advanced_matching_enabled',
			function ( $use_pii ) {
				return false;
			}
		);

		$this->assertTrue( is_admin(), 'Current user must be an admin user.' );
		$this->assertTrue( has_filter( 'wc_facebook_is_advanced_matching_enabled' ) );
		$this->assertTrue( $this->integration->init_pixel() );
		$this->assertEquals(
			[
				WC_Facebookcommerce_Pixel::PIXEL_ID_KEY => '0',
				WC_Facebookcommerce_Pixel::USE_PII_KEY  => false,
				WC_Facebookcommerce_Pixel::USE_S2S_KEY  => false,
				WC_Facebookcommerce_Pixel::ACCESS_TOKEN_KEY => '',
			],
			get_option( WC_Facebookcommerce_Pixel::SETTINGS_KEY )
		);
	}

	/**
	 * Tests loading of background processor.
	 *
	 * @return void
	 */
	public function test_load_background_sync_process() {
		$this->integration->load_background_sync_process();

		$this->assertInstanceOf( WC_Facebookcommerce_Background_Process::class, $this->integration->background_processor );
		$this->assertEquals(
			10,
			has_action(
				'wp_ajax_ajax_fb_background_check_queue',
				[ $this->integration, 'ajax_fb_background_check_queue' ],
			)
		);
	}

	/**
	 * Tests get graph api init and return function.
	 *
	 * @return void
	 */
	public function test_get_graph_api() {
		$this->assertInstanceOf( WC_Facebookcommerce_Graph_API::class, $this->integration->get_graph_api() );
	}

	/**
	 * Tests fetching variable product item ids from product meta.
	 *
	 * @return void
	 */
	public function test_get_variation_product_item_ids_from_meta() {
		/** @var WC_Product_Variable $parent */
		$product = WC_Helper_Product::create_variation_product();
		$product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'some-facebook-product-group-id' );
		$product->save_meta_data();

		$expected_output = [];
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			$variation->add_meta_data(
				WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID,
				'some-facebook-product-item-id-' . $variation_id
			);
			$variation->save_meta_data();

			$expected_output[ $variation_id ] = 'some-facebook-product-item-id-' . $variation_id;
		}
		/* From Product Meta or FB API. */
		$facebook_product_id = 'some-facebook-product-group-id';

		$output = $this->integration->get_variation_product_item_ids( $product, $facebook_product_id );

		$this->assertEquals( $expected_output, $output );
	}

	/**
	 * Tests fetching product item ids from facebook with any filters.
	 *
	 * @return void
	 */
	public function test_get_variation_product_item_ids_from_facebook_with_no_fb_retailer_id_filters() {
		/** @var WC_Product_Variable $parent */
		$product = WC_Helper_Product::create_variation_product();
		$product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'some-facebook-product-group-id' );
		$product->save_meta_data();

		$expected_output = [];
		$facebook_output = [];
		foreach ( $product->get_children() as $variation_id ) {
			$variation                        = wc_get_product( $variation_id );
			$expected_output[ $variation_id ] = 'some-facebook-api-product-item-id-' . $variation_id;
			$facebook_output[]                = [
				'id'          => 'some-facebook-api-product-item-id-' . $variation_id,
				'retailer_id' => $variation->get_sku() . '_' . $variation_id,
			];
		}
		/* From Product Meta or FB API. */
		$facebook_product_id = 'some-facebook-product-group-id';

		$this->integration->fbgraph = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$this->integration->fbgraph->expects( $this->once() )
			->method( 'get_product_group_product_ids' )
			->with( $facebook_product_id )
			->willReturn( [ 'data' => $facebook_output ] );

		$output = $this->integration->get_variation_product_item_ids( $product, $facebook_product_id );

		$this->assertFalse( has_filter( 'wc_facebook_fb_retailer_id' ) );
		$this->assertEquals( $expected_output, $output );
	}

	/**
	 * Tests fetching variable product item ids from facebook with filters.
	 *
	 * @return void
	 */
	public function test_get_variation_product_item_ids_from_facebook_with_fb_retailer_id_filters() {
		/** @var WC_Product_Variable $parent */
		$product = WC_Helper_Product::create_variation_product();
		$product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'some-facebook-product-group-id' );
		$product->save_meta_data();

		$expected_output = [];
		$facebook_output = [];
		foreach ( $product->get_children() as $variation_id ) {
			$variation                        = wc_get_product( $variation_id );
			$expected_output[ $variation_id ] = 'some-facebook-api-product-item-id-' . $variation_id;
			$facebook_output[]                = [
				'id'          => 'some-facebook-api-product-item-id-' . $variation_id,
				'retailer_id' => $variation->get_sku() . '_' . $variation_id . '_modified',
			];
		}
		/* From Product Meta or FB API. */
		$facebook_product_id = 'some-facebook-product-group-id';

		$this->integration->fbgraph = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$this->integration->fbgraph->expects( $this->once() )
			->method( 'get_product_group_product_ids' )
			->with( $facebook_product_id )
			->willReturn( [ 'data' => $facebook_output ] );

		add_filter(
			'wc_facebook_fb_retailer_id',
			function ( $retailer_id ) {
				return $retailer_id . '_modified';
			}
		);

		$output = $this->integration->get_variation_product_item_ids( $product, $facebook_product_id );

		$this->assertTrue( has_filter( 'wc_facebook_fb_retailer_id' ) );
		$this->assertEquals( $expected_output, $output );
	}

	/**
	 * Tests fetching facebook catalog name.
	 *
	 * @return void
	 */
	public function test_get_catalog_name_returns_catalog_name() {
		$catalog_id                 = 'some-facebook_catalog-id';
		$facebook_output            = [
			'name' => 'Facebook for WooCommerce Catalog',
			'id'   => '2536275516506259',
		];
		$this->integration->fbgraph = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$this->integration->fbgraph->expects( $this->once() )
			->method( 'get_catalog' )
			->with( $catalog_id )
			->willReturn( [ 'data' => $facebook_output ] );

		$name = $this->integration->get_catalog_name( $catalog_id );

		$this->assertEquals( 'Facebook for WooCommerce Catalog', $name );
	}

	/**
	 * Tests exception handling when fetching facebook catalog name.
	 *
	 * @return void
	 */
	public function test_get_catalog_name_handles_any_exception() {
		$catalog_id                 = 'some-facebook_catalog-id';
		$this->integration->fbgraph = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$this->integration->fbgraph->method( 'get_catalog' )
			->will( $this->throwException( new Exception() ) );

		$name = $this->integration->get_catalog_name( $catalog_id );

		$this->assertEquals( '', $name );
	}

	/**
	 * Tests user id fetching from facebook.
	 *
	 * @return void
	 */
	public function test_get_user_id_returns_user_id() {
		$facebook_output            = [
			'name' => 'WooCommerce Integration System User',
			'id'   => '111189594891749',
		];
		$this->integration->fbgraph = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$this->integration->fbgraph->expects( $this->once() )
			->method( 'get_user' )
			->willReturn( [ 'data' => $facebook_output ] );

		$id = $this->integration->get_user_id();

		$this->assertEquals( '111189594891749', $id );
	}

	/**
	 * Tests exception handling when fetching user id from facebook.
	 *
	 * @return void
	 */
	public function test_get_user_id_handles_any_exception() {
		$this->integration->fbgraph = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$this->integration->fbgraph->method( 'get_user' )
			->will( $this->throwException( new Exception() ) );

		$id = $this->integration->get_user_id();

		$this->assertEquals( '', $id );
	}

	/**
	 * Tests revoking user permissions.
	 *
	 * @return void
	 */
	public function test_revoke_user_permission_revokes_given_permission() {
		$user_id                    = '111189594891749';
		$permission                 = 'manage_business_extension';
		$facebook_output            = [ 'success' => true ];
		$this->integration->fbgraph = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$this->integration->fbgraph->expects( $this->once() )
			->method( 'revoke_user_permission' )
			->with( $user_id, $permission )
			->willReturn( [ 'data' => $facebook_output ] );

		$response = $this->integration->revoke_user_permission( $user_id, $permission );

		$this->assertTrue( $response );
	}

	/**
	 * Tests exception handling when revoking user permissions.
	 *
	 * @return void
	 */
	public function test_revoke_user_permission_handles_any_exception() {
		$user_id                    = '111189594891749';
		$permission                 = 'manage_business_extension';
		$this->integration->fbgraph = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$this->integration->fbgraph->method( 'revoke_user_permission' )
			->will( $this->throwException( new Exception() ) );

		$response = $this->integration->revoke_user_permission( $user_id, $permission );

		$this->assertFalse( $response );
	}

	/**
	 * Tests sending item updates to facebook.
	 *
	 * @return void
	 */
	public function test_send_item_updates_sends_updates() {
		$catalog_id                 = 'some-facebook_catalog-id';
		$requests                   = [];
		$facebook_output            = [ 'handles' => [ 'AcwiLiSrWtRI_uCzelJ4qe5Ji4AOIjb2vBlrUlXTq6PH9unpjNzWpU_Xhl8JA1ygVFsSzhi8DedPF8TSLAU8YNsb' ] ];
		$this->integration->fbgraph = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$this->integration->fbgraph->expects( $this->once() )
			->method( 'send_item_updates' )
			->with( $catalog_id, $requests )
			->willReturn( [ 'data' => $facebook_output ] );

		$handles = $this->integration->send_item_updates( $catalog_id, $requests );

		$this->assertEquals(
			[ 'AcwiLiSrWtRI_uCzelJ4qe5Ji4AOIjb2vBlrUlXTq6PH9unpjNzWpU_Xhl8JA1ygVFsSzhi8DedPF8TSLAU8YNsb' ],
			$handles
		);
	}

	/**
	 * Tests exception handling when sending item updates to facebook.
	 *
	 * @return void
	 */
	public function test_send_item_updates_handles_any_exception() {
		$catalog_id                 = 'some-facebook_catalog-id';
		$requests                   = [];
		$this->integration->fbgraph = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$this->integration->fbgraph->method( 'send_item_updates' )
			->will( $this->throwException( new Exception() ) );

		$handles = $this->integration->send_item_updates( $catalog_id, $requests );

		$this->assertEquals( [], $handles );
	}

	/**
	 * Tests sensing pixel events into facebook.
	 *
	 * @return void
	 */
	public function test_send_pixel_events_sends_pixel_events() {
		$pixel_id                   = '1964583793745557';
		$events                     = [];
		$facebook_output            = [
			'events_received' => 1,
			'messages'        => [],
			'fbtrace_id'      => 'ACkWGi-ptHPA897dD0liZEg',
		];
		$this->integration->fbgraph = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$this->integration->fbgraph->expects( $this->once() )
			->method( 'send_pixel_events' )
			->with( $pixel_id, $events )
			->willReturn( $facebook_output );

		$result = $this->integration->send_pixel_events( $pixel_id, $events );

		$this->assertTrue( $result );
	}

	/**
	 * Tests exception handling when sending pixel events into facebook.
	 *
	 * @return void
	 */
	public function test_send_pixel_events_handles_any_exception() {
		$pixel_id                   = '1964583793745557';
		$events                     = [];
		$this->integration->fbgraph = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$this->integration->fbgraph->method( 'send_pixel_events' )
			->will( $this->throwException( new Exception() ) );

		$result = $this->integration->send_pixel_events( $pixel_id, $events );

		$this->assertFalse( $result );
	}

	/**
	 * Tests messenger configuration fetching.
	 *
	 * @return void
	 */
	public function test_get_messenger_configuration_returns_messenger_configuration() {
		$external_business_id       = 'wordpress-facebook-627c01b68bc60';
		$facebook_output            = [
			'messenger_chat' => [
				'enabled'        => true,
				'domains'        => [ 'https://somesite.com/' ],
				'default_locale' => 'en_US',
			],
		];
		$this->integration->fbgraph = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$this->integration->fbgraph->expects( $this->once() )
			->method( 'get_business_configuration' )
			->with( $external_business_id )
			->willReturn( $facebook_output );

		$configuration = $this->integration->get_messenger_configuration( $external_business_id );

		$this->assertEquals(
			[
				'enabled'        => true,
				'domains'        => [ 'https://somesite.com/' ],
				'default_locale' => 'en_US',
			],
			$configuration
		);
	}

	/**
	 * Tests default messenger configuration fetching.
	 *
	 * @return void
	 */
	public function test_get_messenger_configuration_returns_default_messenger_configuration() {
		$external_business_id       = 'wordpress-facebook-627c01b68bc60';
		$facebook_output            = [];
		$this->integration->fbgraph = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$this->integration->fbgraph->expects( $this->once() )
			->method( 'get_business_configuration' )
			->with( $external_business_id )
			->willReturn( $facebook_output );

		$configuration = $this->integration->get_messenger_configuration( $external_business_id );

		$this->assertEquals( [], $configuration );
	}

	/**
	 * Tests exceptions handling when fetching messenger configuration.
	 *
	 * @return void
	 */
	public function test_get_messenger_configuration_handles_any_exception() {
		$external_business_id       = 'wordpress-facebook-627c01b68bc60';
		$this->integration->fbgraph = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$this->integration->fbgraph->method( 'get_business_configuration' )
			->will( $this->throwException( new Exception() ) );

		$configuration = $this->integration->get_messenger_configuration( $external_business_id );

		$this->assertEquals( [], $configuration );
	}

	/**
	 * Tests messenger configuration update call.
	 *
	 * @return void
	 */
	public function test_update_messenger_configuration_updates_messenger_configuration() {
		$external_business_id       = 'wordpress-facebook-627c01b68bc60';
		$configuration              = [
			'enabled'        => false,
			'default_locale' => '',
			'domains'        => [],
		];
		$facebook_output            = [ 'success' => true ];
		$this->integration->fbgraph = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$this->integration->fbgraph->expects( $this->once() )
			->method( 'update_messenger_configuration' )
			->with( $external_business_id, $configuration )
			->willReturn( $facebook_output );

		$result = $this->integration->update_messenger_configuration( $external_business_id, $configuration );

		$this->assertTrue( $result );
	}

	/**
	 * Tests exception handling when calling for messenger configuration update.
	 *
	 * @return void
	 */
	public function test_update_messenger_configuration_handles_any_exception() {
		$this->integration->fbgraph = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$this->integration->fbgraph->method( 'update_messenger_configuration' )
			->will( $this->throwException( new Exception() ) );

		$result = $this->integration->update_messenger_configuration( 'wordpress-facebook-627c01b68bc60', [] );

		$this->assertFalse( $result );
	}

	/**
	 * Tests business configuration fetching.
	 *
	 * @return void
	 */
	public function test_get_business_configuration_gets_business_configuration() {
		$external_business_id       = 'wordpress-facebook-627c01b68bc60';
		$facebook_output            = [
			'ig_shopping'    => [ 'enabled' => false ],
			'ig_cta'         => [ 'enabled' => false ],
			'messenger_chat' => [ 'enabled' => false ],
		];
		$this->integration->fbgraph = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$this->integration->fbgraph->expects( $this->once() )
			->method( 'get_business_configuration' )
			->with( $external_business_id )
			->willReturn( $facebook_output );

		$configuration = $this->integration->get_business_configuration( $external_business_id );

		$this->assertArrayHasKey( 'ig_shopping', $configuration );
		$this->assertArrayHasKey( 'ig_cta', $configuration );
		$this->assertArrayHasKey( 'messenger_chat', $configuration );

		$this->assertEquals( [ 'enabled' => false ], $configuration['ig_shopping'] );
		$this->assertEquals( [ 'enabled' => false ], $configuration['ig_cta'] );
		$this->assertEquals( [ 'enabled' => false ], $configuration['messenger_chat'] );
	}

	/**
	 * Tests exception handling when calling to fetch business configuration.
	 *
	 * @return void
	 */
	public function test_get_business_configuration_handles_any_exception() {
		$this->integration->fbgraph = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$this->integration->fbgraph->method( 'get_business_configuration' )
			->will( $this->throwException( new Exception() ) );

		$result = $this->integration->get_business_configuration( 'wordpress-facebook-627c01b68bc60', [] );

		$this->assertEquals( [], $result );
	}

	/**
	 * Tests fetching installation ids from facebook.
	 *
	 * @return void
	 */
	public function test_get_installation_ids_gets_installation_ids() {
		$external_business_id       = 'wordpress-facebook-627c01b68bc60';
		$facebook_output            = [
			'data' => [
				[
					'business_manager_id'           => '973766133343161',
					'commerce_merchant_settings_id' => '400812858215678',
					'onsite_eligible'               => false,
					'pixel_id'                      => '1964583793745557',
					'profiles'                      => [ '100564162645958' ],
					'ad_account_id'                 => '0',
					'catalog_id'                    => '2536275516506259',
					'pages'                         => [ '100564162645958' ],
					'token_type'                    => 'User',
					'installed_features'            => [
						[
							'feature_instance_id' => '2581241938677946',
							'feature_type'        => 'messenger_chat',
							'connected_assets'    => [ 'page_id' => '100564162645958' ],
							'additional_info'     => [ 'onsite_eligible' => false ],
						],
						[
							'feature_instance_id' => '342416671202958',
							'feature_type'        => 'fb_shop',
							'connected_assets'    =>
							[
								'catalog_id' => '2536275516506259',
								'commerce_merchant_settings_id' => '400812858215678',
								'page_id'    => '100564162645958',
							],
							'additional_info'     => [ 'onsite_eligible' => false ],
						],
						[
							'feature_instance_id' => '1468417443607539',
							'feature_type'        => 'pixel',
							'connected_assets'    => [
								'page_id'  => '100564162645958',
								'pixel_id' => '1964583793745557',
							],
							'additional_info'     => [ 'onsite_eligible' => false ],
						],
						[
							'feature_instance_id' => '1150084395846296',
							'feature_type'        => 'catalog',
							'connected_assets'    => [
								'catalog_id' => '2536275516506259',
								'page_id'    => '100564162645958',
								'pixel_id'   => '1964583793745557',
							],
							'additional_info'     => [ 'onsite_eligible' => false ],
						],
					],
				],
			],
		];
		$this->integration->fbgraph = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$this->integration->fbgraph->expects( $this->once() )
			->method( 'get_installation_ids' )
			->with( $external_business_id )
			->willReturn( $facebook_output );

		$results = $this->integration->get_installation_ids( $external_business_id );

		$results = current( $results );

		$this->assertArrayHasKey( 'pages', $results );
		$this->assertArrayHasKey( 'pixel_id', $results );
		$this->assertArrayHasKey( 'catalog_id', $results );
		$this->assertArrayHasKey( 'business_manager_id', $results );
		$this->assertArrayHasKey( 'ad_account_id', $results );
		$this->assertArrayHasKey( 'commerce_merchant_settings_id', $results );

		$this->assertEquals( [ '100564162645958' ], $results['pages'] );
		$this->assertEquals( '1964583793745557', $results['pixel_id'] );
		$this->assertEquals( '2536275516506259', $results['catalog_id'] );
		$this->assertEquals( '973766133343161', $results['business_manager_id'] );
		$this->assertEquals( '0', $results['ad_account_id'] );
		$this->assertEquals( '400812858215678', $results['commerce_merchant_settings_id'] );
	}

	/**
	 * Tests exception handling when calling for installation ids from facebook.
	 *
	 * @return void
	 */
	public function test_get_installation_ids_handles_any_exception() {
		$this->integration->fbgraph = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$this->integration->fbgraph->method( 'get_installation_ids' )
			->will( $this->throwException( new Exception() ) );

		$result = $this->integration->get_installation_ids( 'wordpress-facebook-627c01b68bc60', [] );

		$this->assertEquals( [], $result );
	}

	/**
	 * Tests fetching page access token for a given page id returns a corresponding token.
	 *
	 * @return void
	 */
	public function test_retrieve_page_access_token_retrieves_a_token() {
		$page_id                    = '100564162645958';
		$facebook_output            = [
			'data' => [
				[
					'id'           => '100564162645958',
					'name'         => 'Dima for WooCommerce Second Page',
					'category'     => 'E-commerce website',
					'access_token' => 'EAAGvQJc4NAQBAJCNYEmiQhS9tEL0RBtyZAkuYZAbhHCdPymmakc2L3cwCCfY6fh2bD7u7LA7hapY6IfRw5xQqpO324K749GHl46NUNByhbKDBXfUq33JM5lIOucbdZBAc6FrqkZBleLZBaCjVWBsQ1ticFay9iNmw9tMSIml4i6MRyPw4t4dXmK5LQZCD1oUzKeYkCICnEOgZDZD',
				],
				[
					'id'           => '109649988385192',
					'name'         => 'My Local Woo Commerce Store Page',
					'category'     => 'E-commerce website',
					'access_token' => 'EAAGvQJc4NAQBAGpwt4W1JYnG6OvLZCXWOpv713bWRDdWtEjy8c8bHonrZCKW0Q7sYf4a1AR0rW2C0p8XqOWwroQnZBP1peH986oB9fjxy8WCZBOb9bM3j50532TBWTT9ehDthXbJyheaTugj1qhmttfehS3nmGmG8gN3dGSwfqUcIDBgCG5CZC0vR22cajhUfaV2CfJ2qUgZDZD',
				],
			],
		];
		$this->integration->fbgraph = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$this->integration->fbgraph->expects( $this->once() )
			->method( 'retrieve_page_access_token' )
			->willReturn( $facebook_output );

		$token = $this->integration->retrieve_page_access_token( $page_id );

		$this->assertEquals(
			'EAAGvQJc4NAQBAJCNYEmiQhS9tEL0RBtyZAkuYZAbhHCdPymmakc2L3cwCCfY6fh2bD7u7LA7hapY6IfRw5xQqpO324K749GHl46NUNByhbKDBXfUq33JM5lIOucbdZBAc6FrqkZBleLZBaCjVWBsQ1ticFay9iNmw9tMSIml4i6MRyPw4t4dXmK5LQZCD1oUzKeYkCICnEOgZDZD',
			$token
		);
	}

	/**
	 * Tests fetching access token for a missing page id returns empty string.
	 *
	 * @return void
	 */
	public function test_retrieve_page_access_token_retrieves_a_token_for_a_missing_page_id() {
		$page_id                    = '999999999999999';
		$facebook_output            = [
			'data' => [
				[
					'id'           => '100564162645958',
					'name'         => 'Dima for WooCommerce Second Page',
					'category'     => 'E-commerce website',
					'access_token' => 'EAAGvQJc4NAQBAJCNYEmiQhS9tEL0RBtyZAkuYZAbhHCdPymmakc2L3cwCCfY6fh2bD7u7LA7hapY6IfRw5xQqpO324K749GHl46NUNByhbKDBXfUq33JM5lIOucbdZBAc6FrqkZBleLZBaCjVWBsQ1ticFay9iNmw9tMSIml4i6MRyPw4t4dXmK5LQZCD1oUzKeYkCICnEOgZDZD',
				],
				[
					'id'           => '109649988385192',
					'name'         => 'My Local Woo Commerce Store Page',
					'category'     => 'E-commerce website',
					'access_token' => 'EAAGvQJc4NAQBAGpwt4W1JYnG6OvLZCXWOpv713bWRDdWtEjy8c8bHonrZCKW0Q7sYf4a1AR0rW2C0p8XqOWwroQnZBP1peH986oB9fjxy8WCZBOb9bM3j50532TBWTT9ehDthXbJyheaTugj1qhmttfehS3nmGmG8gN3dGSwfqUcIDBgCG5CZC0vR22cajhUfaV2CfJ2qUgZDZD',
				],
			],
		];
		$this->integration->fbgraph = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$this->integration->fbgraph->expects( $this->once() )
			->method( 'retrieve_page_access_token' )
			->willReturn( $facebook_output );

		$token = $this->integration->retrieve_page_access_token( $page_id );

		$this->assertEquals( '', $token );
	}

	/**
	 * Tests exception handling when fetching page access token.
	 *
	 * @return void
	 */
	public function test_retrieve_page_access_token_handles_any_exception() {
		$this->integration->fbgraph = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$this->integration->fbgraph->method( 'retrieve_page_access_token' )
			->will( $this->throwException( new Exception() ) );

		$token = $this->integration->retrieve_page_access_token( '100564162645958' );

		$this->assertEquals( '', $token );
	}

	/**
	 * Tests fetching product count without filters.
	 *
	 * @return void
	 */
	public function test_get_product_count_returns_product_count_with_no_filters() {
		$count = $this->integration->get_product_count();

		$this->assertFalse( has_filter( 'wp_count_posts' ) );
		$this->assertEquals( 0, $count );

		WC_Helper_Product::create_simple_product();
		WC_Helper_Product::create_simple_product();

		$count = $this->integration->get_product_count();

		$this->assertFalse( has_filter( 'wp_count_posts' ) );
		$this->assertEquals( 2, $count );
	}

	/**
	 * Tests filters overwrite product counts.
	 *
	 * @return void
	 */
	public function test_get_product_count_returns_product_count_with_filters() {
		add_filter(
			'wp_count_posts',
			function( $counts ) {
				$counts->publish = 21;
				return $counts;
			}
		);

		$count = $this->integration->get_product_count();

		$this->assertEquals( 10, has_filter( 'wp_count_posts' ) );
		$this->assertEquals( 21, $count );
	}

	/**
	 * Tests default allow full batch api sync status.
	 *
	 * @return void
	 */
	public function test_allow_full_batch_api_sync_returns_default_allow_status_with_no_filters() {
		$status = $this->integration->allow_full_batch_api_sync();

		$this->assertTrue( $status );
		$this->assertFalse( has_filter( 'facebook_for_woocommerce_block_full_batch_api_sync' ) );
		$this->assertFalse( has_filter( 'facebook_for_woocommerce_allow_full_batch_api_sync' ) );
	}

	/**
	 * Tests default allow full batch api sync uses facebook_for_woocommerce_block_full_batch_api_sync filter
	 * to overwrite allowance status.
	 *
	 * @return void
	 */
	public function test_allow_full_batch_api_sync_uses_block_full_batch_api_sync_filter() {
		add_filter(
			'facebook_for_woocommerce_block_full_batch_api_sync',
			function ( bool $status ) {
				return true;
			}
		);

		$status = $this->integration->allow_full_batch_api_sync();

		$this->assertFalse( $status );
		$this->assertTrue( has_filter( 'facebook_for_woocommerce_block_full_batch_api_sync' ) );
		$this->assertFalse( has_filter( 'facebook_for_woocommerce_allow_full_batch_api_sync' ) );
	}

	/**
	 * Tests default allow full batch api sync uses facebook_for_woocommerce_allow_full_batch_api_sync filter
	 * to overwrite allowance status.
	 *
	 * @return void
	 */
	public function test_allow_full_batch_api_sync_uses_allow_full_batch_api_sync_filter() {
		$this->markTestSkipped( 'Some problems with phpunit polyfills notices handling.' );

		add_filter(
			'facebook_for_woocommerce_allow_full_batch_api_sync',
			function ( bool $status ) {
				return false;
			}
		);

		$status = $this->integration->allow_full_batch_api_sync();

		$this->assertFalse( $status );
		$this->assertFalse( has_filter( 'facebook_for_woocommerce_block_full_batch_api_sync' ) );
		$this->assertTrue( has_filter( 'facebook_for_woocommerce_allow_full_batch_api_sync' ) );
	}

	/**
	 * Tests plugin enqueues scripts and styles for non admin user for non plugin settings screens.
	 *
	 * @return void
	 */
	public function test_load_assets_loads_only_info_banner_assets_for_not_admin_or_not_a_plugin_settings_page() {
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'is_plugin_settings' )
			->willReturn( false );

		$this->integration->load_assets();

		do_action( 'wp_enqueue_scripts' );
		do_action( 'wp_enqueue_styles' );

		$this->assertTrue( wp_script_is( 'wc_facebook_infobanner_jsx' ) );
		$this->assertTrue( wp_style_is( 'wc_facebook_infobanner_css' ) );
		$this->assertFalse( wp_style_is( 'wc_facebook_css' ) );
	}

	/**
	 * Tests plugin enqueues scripts and styles for admin user for plugin settings screens.
	 *
	 * @return void
	 */
	public function test_load_assets_loads_only_info_banner_assets_for_admin_at_plugin_settings_page() {
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'is_plugin_settings' )
			->willReturn( true );

		ob_start();
		$this->integration->load_assets();
		$output = ob_get_clean();

		do_action( 'wp_enqueue_scripts' );
		do_action( 'wp_enqueue_styles' );

		$this->assertTrue( wp_script_is( 'wc_facebook_infobanner_jsx' ) );
		$this->assertTrue( wp_style_is( 'wc_facebook_infobanner_css' ) );
		$this->assertTrue( wp_style_is( 'wc_facebook_css' ) );
		$this->assertRegExp( '/window.facebookAdsToolboxConfig = {/', $output );
	}

	/**
	 * Sunny day test with all the conditions evaluated to true and maximum conditions triggered.
	 *
	 * @return void
	 */
	public function test_on_product_save_existing_simple_product_sync_enabled_updates_the_product() {
		$product_to_update = WC_Helper_Product::create_simple_product();
		$product_to_delete = WC_Helper_Product::create_simple_product();

		$_POST['wc_facebook_sync_mode'] = Admin::SYNC_MODE_SYNC_AND_SHOW;

		$_POST[ WC_Facebook_Product::FB_REMOVE_FROM_SYNC ] = $product_to_delete->get_id();

		$_POST[ WC_Facebookcommerce_Integration::FB_PRODUCT_DESCRIPTION ] = 'Facebook product description.';
		$_POST[ WC_Facebook_Product::FB_PRODUCT_PRICE ]                   = '199';
		$_POST['fb_product_image_source']                                 = 'Image source meta key value.';
		$_POST[ WC_Facebook_Product::FB_PRODUCT_IMAGE ]                   = 'Facebook product image.';
		$_POST[ AdminProducts::FIELD_COMMERCE_ENABLED ]                   = 1;
		$_POST[ AdminProducts::FIELD_GOOGLE_PRODUCT_CATEGORY_ID ]         = 1718;

		$_POST[ Enhanced_Catalog_Attribute_Fields::FIELD_ENHANCED_CATALOG_ATTRIBUTE_PREFIX . '_attr1' ] = 'Enhanced catalog attribute one.';
		$_POST[ Enhanced_Catalog_Attribute_Fields::FIELD_ENHANCED_CATALOG_ATTRIBUTE_PREFIX . '_attr2' ] = 'Enhanced catalog attribute two.';
		$_POST[ Enhanced_Catalog_Attribute_Fields::FIELD_ENHANCED_CATALOG_ATTRIBUTE_PREFIX . '_attr3' ] = 'Enhanced catalog attribute three.';
		$_POST[ Enhanced_Catalog_Attribute_Fields::FIELD_ENHANCED_CATALOG_ATTRIBUTE_PREFIX . '_attr4' ] = 'Enhanced catalog attribute four.';

		$validator = $this->createMock( ProductValidator::class );
		$validator->expects( $this->once() )->method( 'validate' );
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_product_sync_validator' )
			->with( $product_to_update )
			->willReturn( $validator );

		$product_to_update->set_stock_status( 'instock' );

		add_post_meta( $product_to_update->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'facebook-product-item-id' );

		$product_to_update->set_meta_data( Products::VISIBILITY_META_KEY, true );

		$facebook_output_update_product_item            = [
			'headers'  => [],
			'body'     => '{"id":"5191364664265911"}',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];
		$facebook_product                               = new WC_Facebook_Product( $product_to_update->get_id() );
		$facebook_product_data                          = $facebook_product->prepare_product();
		$facebook_product_data['additional_image_urls'] = '';
		/* Data coming from _POST data. */
		$facebook_product_data['description'] = 'Facebook product description.';
		$facebook_product_data['price']       = 19900;

		$graph_api = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$graph_api->expects( $this->once() )
			->method( 'update_product_item' )
			->with( 'facebook-product-item-id', $facebook_product_data )
			->willReturn( $facebook_output_update_product_item );
		$this->integration->fbgraph = $graph_api;

		$this->integration->on_product_save( $product_to_update->get_id() );

		$this->assertEquals( 'yes', get_post_meta( $product_to_update->get_id(), Products::SYNC_ENABLED_META_KEY, true ) );
		$this->assertEquals( 'yes', get_post_meta( $product_to_update->get_id(), Products::VISIBILITY_META_KEY, true ) );
		$this->assertEquals( 'imagesourcemetakeyvalue', get_post_meta( $product_to_update->get_id(), Products::PRODUCT_IMAGE_SOURCE_META_KEY, true ) );

		$this->assertEquals( 'Enhanced catalog attribute one.', get_post_meta( $product_to_update->get_id(), Products::ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX . '_attr1', true ) );
		$this->assertEquals( 'Enhanced catalog attribute two.', get_post_meta( $product_to_update->get_id(), Products::ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX . '_attr2', true ) );
		$this->assertEquals( 'Enhanced catalog attribute three.', get_post_meta( $product_to_update->get_id(), Products::ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX . '_attr3', true ) );
		$this->assertEquals( 'Enhanced catalog attribute four.', get_post_meta( $product_to_update->get_id(), Products::ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX . '_attr4', true ) );

		$this->assertEquals( null, get_post_meta( $product_to_update->get_id(), Enhanced_Catalog_Attribute_Fields::OPTIONAL_SELECTOR_KEY, true ) );
		$this->assertEquals( 'yes', get_post_meta( $product_to_update->get_id(), Products::COMMERCE_ENABLED_META_KEY, true ) );
		$this->assertEquals( 1718, get_post_meta( $product_to_update->get_id(), Products::GOOGLE_PRODUCT_CATEGORY_META_KEY, true ) );

		$facebook_product_to_update = new WC_Facebook_Product( $product_to_update->get_id() );

		$this->assertEquals( 'Facebook product description.', get_post_meta( $facebook_product_to_update->get_id(), WC_Facebook_Product::FB_PRODUCT_DESCRIPTION, true ) );
		$this->assertEquals( '199', get_post_meta( $facebook_product_to_update->get_id(), WC_Facebook_Product::FB_PRODUCT_PRICE, true ) );
		$this->assertEquals( 'http://example.orgFacebook product image.', get_post_meta( $facebook_product_to_update->get_id(), WC_Facebook_Product::FB_PRODUCT_IMAGE, true ) );
	}

	/**
	 * Sunny day test with all the conditions evaluated to true and maximum conditions triggered.
	 *
	 * @return void
	 */
	public function test_on_product_save_existing_variable_product_sync_enabled_updates_the_product() {
		$graph_api = $this->createMock( WC_Facebookcommerce_Graph_API::class );

		$parent           = WC_Helper_Product::create_variation_product();
		$fb_product       = new WC_Facebook_Product( $parent->get_id() );
		$parent_to_delete = WC_Helper_Product::create_variation_product();

		$facebook_output_update_product_group = [
			'headers'  => [],
			'body'     => '{"id":"5191364664265911"}',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];

		$_POST['wc_facebook_sync_mode']                           = Admin::SYNC_MODE_SYNC_AND_SHOW;
		$_POST[ WC_Facebook_Product::FB_REMOVE_FROM_SYNC ]        = $parent_to_delete->get_id();
		$_POST[ AdminProducts::FIELD_COMMERCE_ENABLED ]           = 1;
		$_POST[ AdminProducts::FIELD_GOOGLE_PRODUCT_CATEGORY_ID ] = 1920;

		$_POST[ Enhanced_Catalog_Attribute_Fields::FIELD_ENHANCED_CATALOG_ATTRIBUTE_PREFIX . '_attr1' ] = 'Enhanced catalog attribute one.';
		$_POST[ Enhanced_Catalog_Attribute_Fields::FIELD_ENHANCED_CATALOG_ATTRIBUTE_PREFIX . '_attr2' ] = 'Enhanced catalog attribute two.';
		$_POST[ Enhanced_Catalog_Attribute_Fields::FIELD_ENHANCED_CATALOG_ATTRIBUTE_PREFIX . '_attr3' ] = 'Enhanced catalog attribute three.';
		$_POST[ Enhanced_Catalog_Attribute_Fields::FIELD_ENHANCED_CATALOG_ATTRIBUTE_PREFIX . '_attr4' ] = 'Enhanced catalog attribute four.';

		add_post_meta( $parent_to_delete->get_id(), ProductValidator::SYNC_ENABLED_META_KEY, 'no' );
		foreach ( $parent_to_delete->get_children() as $id ) {
			add_post_meta( $id, ProductValidator::SYNC_ENABLED_META_KEY, 'no' );
		}

		$sync = $this->createMock( Products\Sync::class );
		$sync->expects( $this->once() )
			->method( 'delete_products' )
			->with(
				array_map(
					function ( $id ) {
						return 'wc_post_id_' . $id;
					},
					$parent_to_delete->get_children()
				)
			);
		$sync->expects( $this->once() )
			->method( 'create_or_update_products' )
			->with( $parent->get_children() );
		$this->facebook_for_woocommerce->expects( $this->exactly( 2 ) )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync );

		add_post_meta( $parent->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'facebook-variable-product-group-item-id' );

		$validator = $this->createMock( ProductValidator::class );
		$validator->expects( $this->exactly( 7 ) )->method( 'validate' );
		$this->facebook_for_woocommerce->expects( $this->exactly( 7 ) )
			->method( 'get_product_sync_validator' )
			->willReturn( $validator );

		$parent->set_meta_data( Products::VISIBILITY_META_KEY, true );

		$graph_api->expects( $this->once() )
			->method( 'update_product_group' )
			->with(
				'facebook-variable-product-group-item-id',
				[
					'variants' => $fb_product->prepare_variants_for_group(),
				]
			)
			->willReturn( $facebook_output_update_product_group );
		$this->integration->fbgraph = $graph_api;

		$this->integration->on_product_save( $parent->get_id() );

		$this->assertEquals( 'Enhanced catalog attribute one.', get_post_meta( $parent->get_id(), Products::ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX . '_attr1', true ) );
		$this->assertEquals( 'Enhanced catalog attribute two.', get_post_meta( $parent->get_id(), Products::ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX . '_attr2', true ) );
		$this->assertEquals( 'Enhanced catalog attribute three.', get_post_meta( $parent->get_id(), Products::ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX . '_attr3', true ) );
		$this->assertEquals( 'Enhanced catalog attribute four.', get_post_meta( $parent->get_id(), Products::ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX . '_attr4', true ) );

		$this->assertEquals( null, get_post_meta( $parent->get_id(), Enhanced_Catalog_Attribute_Fields::OPTIONAL_SELECTOR_KEY, true ) );
		$this->assertEquals( 'yes', get_post_meta( $parent->get_id(), Products::COMMERCE_ENABLED_META_KEY, true ) );
		$this->assertEquals( 1920, get_post_meta( $parent->get_id(), Products::GOOGLE_PRODUCT_CATEGORY_META_KEY, true ) );
	}

	/**
	 * Sunny day test with all the conditions evaluated to true and maximum conditions triggered.
	 *
	 * @return void
	 */
	public function test_on_product_delete_simple_product() {
		$product_to_delete = WC_Helper_Product::create_simple_product();

		add_post_meta( $product_to_delete->get_id(), ProductValidator::SYNC_ENABLED_META_KEY, 'yes' );
		add_post_meta( $product_to_delete->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'facebook-product-id' );
		add_post_meta( $product_to_delete->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'facebook-product-group-id' );

		$graph_api = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$graph_api->expects( $this->once() )
			->method( 'delete_product_item' )
			->with( 'facebook-product-id' );
		$graph_api->expects( $this->once() )
			->method( 'delete_product_group' )
			->with( 'facebook-product-group-id' );
		$this->integration->fbgraph = $graph_api;

		$this->integration->on_product_delete( $product_to_delete->get_id() );

		$this->assertEquals( null, get_post_meta( $product_to_delete->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
		$this->assertEquals( null, get_post_meta( $product_to_delete->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
	}

	/**
	 * Sunny day test with all the conditions evaluated to true and maximum conditions triggered.
	 *
	 * @return void
	 */
	public function test_on_product_delete_variable_product() {
		$parent_to_delete = WC_Helper_Product::create_variation_product();

		$sync_handler = $this->createMock( Products\Sync::class );
		$sync_handler->expects( $this->once() )
			->method( 'delete_products' )
			->with(
				array_map(
					function ( $id ) {
						return WC_Facebookcommerce_Utils::get_fb_retailer_id( wc_get_product( $id ) );
					},
					$parent_to_delete->get_children()
				)
			);
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync_handler );

		$this->integration->on_product_delete( $parent_to_delete->get_id() );
	}

	/**
	 * Sunny day test with all the conditions evaluated to true and maximum conditions triggered.
	 *
	 * @return void
	 */
	public function test_fb_change_product_published_status_for_simple_product() {
		add_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PAGE_ID, 'facebook-page-id' );
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, '1234567891011121314' );

		$this->connection_handler->expects( $this->once() )
			->method( 'is_connected' )
			->willReturn( true );

		$product = WC_Helper_Product::create_simple_product();

		add_post_meta( $product->get_id(), ProductValidator::SYNC_ENABLED_META_KEY, 'yes' );
		add_post_meta( $product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'facebook-product-id' );

		$graph_api                           = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$facebook_output_update_product_item = [
			'headers'  => [],
			'body'     => '', // Does not matter much we check only response code.
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];
		$graph_api->expects( $this->once() )
			->method( 'update_product_item' )
			->with(
				'facebook-product-id',
				[ 'visibility' => WC_Facebookcommerce_Integration::FB_SHOP_PRODUCT_VISIBLE ]
			)
			->willReturn( $facebook_output_update_product_item );
		$this->integration->fbgraph = $graph_api;

		/* Statuses involved into logic: publish, trash */
		$new_status = 'publish';
		$old_status = 'trash';

		$data     = new stdClass();
		$data->ID = $product->get_id();
		$post     = new WP_Post( $data );

		$this->integration->fb_change_product_published_status( $new_status, $old_status, $post );

		$this->assertEquals( 'yes', get_post_meta( $product->get_id(), Products::VISIBILITY_META_KEY, true ) );
	}

	/**
	 * Sunny day test with all the conditions evaluated to true and maximum conditions triggered.
	 *
	 * @return void
	 */
	public function test_fb_change_product_published_status_for_variable_product() {
		add_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PAGE_ID, 'facebook-page-id' );
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, '1234567891011121314' );

		/** @var WC_Product_Variable $product */
		$product = WC_Helper_Product::create_variation_product();

		$this->connection_handler->expects( $this->once() )
			->method( 'is_connected' )
			->willReturn( true );

		$sync_handler = $this->createMock( Products\Sync::class );
		$sync_handler->expects( $this->once() )
			->method( 'create_or_update_products' )
			->with( $product->get_children() );

		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync_handler );

		/* Statuses involved into logic: publish, trash */
		$new_status = 'publish';
		$old_status = 'trash';

		$data     = new stdClass();
		$data->ID = $product->get_id();
		$post     = new WP_Post( $data );

		$this->integration->fb_change_product_published_status( $new_status, $old_status, $post );

		$this->assertEquals( 'yes', get_post_meta( $product->get_id(), Products::VISIBILITY_META_KEY, true ) );
		foreach ( $product->get_children() as $id ) {
			$this->assertEquals( 'yes', get_post_meta( $id, Products::VISIBILITY_META_KEY, true ) );
		}
	}

	/**
	 * Sunny day test with all the conditions evaluated to true and maximum conditions triggered.
	 *
	 * @return void
	 */
	public function test_on_product_publish_simple_product() {
		add_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PAGE_ID, 'facebook-page-id' );
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, '1234567891011121314' );

		$product = WC_Helper_Product::create_simple_product();

		add_post_meta( $product->get_id(), ProductValidator::SYNC_ENABLED_META_KEY, 'yes' );
		add_post_meta( $product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'facebook-product-item-id' );

		$this->connection_handler->expects( $this->once() )
			->method( 'is_connected' )
			->willReturn( true );

		$validator = $this->createMock( ProductValidator::class );
		$validator->expects( $this->once() )
			->method( 'validate' );
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_product_sync_validator' )
			->with( $product )
			->willReturn( $validator );

		$facebook_output_update_product_item            = [
			'headers'  => [],
			'body'     => '', // Does not matter much we check only response code.
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];
		$facebook_product                               = new WC_Facebook_Product( $product->get_id() );
		$facebook_product_data                          = $facebook_product->prepare_product();
		$facebook_product_data['additional_image_urls'] = '';

		$graph_api = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$graph_api->expects( $this->once() )
			->method( 'update_product_item' )
			->with( 'facebook-product-item-id', $facebook_product_data )
			->willReturn( $facebook_output_update_product_item );
		$this->integration->fbgraph = $graph_api;

		$this->integration->on_product_publish( $product->get_id() );
	}

	/**
	 * Sunny day test with all the conditions evaluated to true and maximum conditions triggered.
	 *
	 * @return void
	 */
	public function test_on_product_publish_variable_product() {
		add_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PAGE_ID, 'facebook-page-id' );
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, '1234567891011121314' );

		$product          = WC_Helper_Product::create_variation_product();
		$facebook_product = new WC_Facebook_Product( $product->get_id() );

		add_post_meta( $product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'facebook-variable-product-group-item-id' );

		$this->connection_handler->expects( $this->once() )
			->method( 'is_connected' )
			->willReturn( true );

		$validator = $this->createMock( ProductValidator::class );
		$validator->expects( $this->exactly( 7 ) )
			->method( 'validate' );
		$this->facebook_for_woocommerce->expects( $this->exactly( 7 ) )
			->method( 'get_product_sync_validator' )
			->willReturn( $validator );

		$facebook_output_update_product_group = [
			'headers'  => [],
			'body'     => '{"id":"5191364664265911"}',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];
		$graph_api                            = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$graph_api->expects( $this->once() )
			->method( 'update_product_group' )
			->with(
				'facebook-variable-product-group-item-id',
				[
					'variants' => $facebook_product->prepare_variants_for_group(),
				]
			)
			->willReturn( $facebook_output_update_product_group );
		$this->integration->fbgraph = $graph_api;

		$sync_handler = $this->createMock( Products\Sync::class );
		$sync_handler->expects( $this->once() )
			->method( 'create_or_update_products' )
			->with( $facebook_product->get_children() );
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync_handler );

		$this->integration->on_product_publish( $product->get_id() );
	}

	/**
	 * Sunny day test. Tests deletion of out of stock simple product item.
	 *
	 * @return void
	 */
	public function test_delete_on_out_of_stock_deletes_simple_product() {
		$product = WC_Helper_Product::create_simple_product();

		update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
		$product->set_stock_status( 'outofstock' );

		add_post_meta( $product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'facebook-product-item-id' );

		$graph_api = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$graph_api->expects( $this->once() )
			->method( 'delete_product_item' )
			->with( 'facebook-product-item-id' );
		$this->integration->fbgraph = $graph_api;

		$result = $this->integration->delete_on_out_of_stock( $product->get_id(), $product );

		$this->assertTrue( $result );
	}

	/**
	 * Tests deletion of out of stock simple product item not performed due to WC settings set to 'no'.
	 *
	 * @return void
	 */
	public function test_delete_on_out_of_stock_does_not_delete_simple_product_with_wc_settings_off() {
		$product = WC_Helper_Product::create_simple_product();

		update_option( 'woocommerce_hide_out_of_stock_items', 'no' );
		$product->set_stock_status( 'outofstock' );

		$graph_api = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$graph_api->expects( $this->never() )
			->method( 'delete_product_item' );
		$this->integration->fbgraph = $graph_api;

		$result = $this->integration->delete_on_out_of_stock( $product->get_id(), $product );

		$this->assertFalse( $result );
	}

	/**
	 * Tests deletion of in-stock variation product item not performed.
	 *
	 * @return void
	 */
	public function test_delete_on_out_of_stock_does_not_delete_in_stock_simple_product() {
		$product = WC_Helper_Product::create_variation_product();

		update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
		$product->set_stock_status( 'instock' );

		$graph_api = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$graph_api->expects( $this->never() )
			->method( 'delete_product_item' );
		$this->integration->fbgraph = $graph_api;

		$result = $this->integration->delete_on_out_of_stock( $product->get_id(), $product );

		$this->assertFalse( $result );
	}

	/**
	 * Tests update of existing variable product.
	 *
	 * @return void
	 */
	public function test_on_variable_product_publish_existing_product_updates_product_group() {
		$product          = WC_Helper_Product::create_variation_product();
		$facebook_product = new WC_Facebook_Product( $product->get_id() );

		/* Product should be synced with all its variations. So seven calls expected. */
		$validator = $this->createMock( ProductValidator::class );
		$validator->expects( $this->exactly( 7 ) )
			->method( 'validate' );
		$this->facebook_for_woocommerce->expects( $this->exactly( 7 ) )
			->method( 'get_product_sync_validator' )
			->willReturn( $validator );

		update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
		$facebook_product->woo_product->set_stock_status( 'instock' );

		add_post_meta( $product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'facebook-variable-product-group-item-id' );

		$facebook_output_update_product_group = [
			'headers'  => [],
			'body'     => '{"id":"5191364664265911"}',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];
		$graph_api                            = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$graph_api->expects( $this->once() )
			->method( 'update_product_group' )
			->with(
				'facebook-variable-product-group-item-id',
				[
					'variants' => $facebook_product->prepare_variants_for_group(),
				]
			)
			->willReturn( $facebook_output_update_product_group );
		$this->integration->fbgraph = $graph_api;

		$sync_handler = $this->createMock( Products\Sync::class );
		$sync_handler->expects( $this->once() )
			->method( 'create_or_update_products' )
			->with( $facebook_product->get_children() );
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync_handler );

		$this->integration->on_variable_product_publish( $product->get_id(), $facebook_product );
	}

	/**
	 * Tests update of existing variable product.
	 *
	 * @return void
	 */
	public function test_on_variable_product_publish_new_product_creates_product() {
		$product          = WC_Helper_Product::create_variation_product();
		$facebook_product = new WC_Facebook_Product( $product->get_id() );

		/* Product should be synced with all its variations. So seven calls expected. */
		$validator = $this->createMock( ProductValidator::class );
		$validator->expects( $this->exactly( 7 ) )
			->method( 'validate' );
		$this->facebook_for_woocommerce->expects( $this->exactly( 7 ) )
			->method( 'get_product_sync_validator' )
			->willReturn( $validator );

		update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
		$facebook_product->woo_product->set_stock_status( 'instock' );

		add_post_meta( $product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, '' );

		$facebook_output_create_product_group = [
			'headers'  => [],
			'body'     => '{"id":"facebook-variable-product-group-item-id"}',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];
		$graph_api                            = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$graph_api->expects( $this->once() )
			->method( 'create_product_group' )
			->willReturn( $facebook_output_create_product_group );
		$this->integration->fbgraph = $graph_api;

		$sync_handler = $this->createMock( Products\Sync::class );
		$sync_handler->expects( $this->once() )
			->method( 'create_or_update_products' )
			->with( $facebook_product->get_children() );
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync_handler );

		$this->integration->on_variable_product_publish( $product->get_id(), $facebook_product );

		$this->assertEquals(
			'facebook-variable-product-group-item-id',
			get_post_meta( $facebook_product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true )
		);
	}

	/**
	 * Tests on simple product publish update callback/hook updates existing product.
	 *
	 * @return void
	 */
	public function test_on_simple_product_publish_existing_product_updates_product() {
		$product          = WC_Helper_Product::create_simple_product();
		$facebook_product = new WC_Facebook_Product( $product->get_id() );

		/* Product should be synced with all its variations. So seven calls expected. */
		$validator = $this->createMock( ProductValidator::class );
		$validator->expects( $this->once() )
			->method( 'validate' );
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_product_sync_validator' )
			->with( $facebook_product->woo_product )
			->willReturn( $validator );

		update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
		$facebook_product->woo_product->set_stock_status( 'instock' );
		add_post_meta( $product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'facebook-simple-product-item-id' );

		$facebook_output_update_product_item            = [
			'headers'  => [],
			'body'     => '{"id":"5191364664265911"}',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];
		$facebook_product_data                          = $facebook_product->prepare_product();
		$facebook_product_data['additional_image_urls'] = '';
		$graph_api                                      = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$graph_api->expects( $this->once() )
			->method( 'update_product_item' )
			->with( 'facebook-simple-product-item-id', $facebook_product_data )
			->willReturn( $facebook_output_update_product_item );
		$this->integration->fbgraph = $graph_api;

		$facebook_product_item_id = $this->integration->on_simple_product_publish( $product->get_id(), $facebook_product );

		$this->assertEquals( 'facebook-simple-product-item-id', $facebook_product_item_id );
	}

	/**
	 * Tests on simple product publish update callback/hook creates new product.
	 *
	 * @return void
	 */
	public function test_on_simple_product_publish_existing_product_creates_product() {
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, '1234567891011121314' );

		$product          = WC_Helper_Product::create_simple_product();
		$facebook_product = new WC_Facebook_Product( $product->get_id() );

		/* Product should be synced with all its variations. So seven calls expected. */
		$validator = $this->createMock( ProductValidator::class );
		$validator->expects( $this->once() )
			->method( 'validate' );
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_product_sync_validator' )
			->with( $facebook_product->woo_product )
			->willReturn( $validator );

		update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
		$facebook_product->woo_product->set_stock_status( 'instock' );
		add_post_meta( $product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, '' );

		$facebook_output_create_product_group = [
			'headers'  => [],
			'body'     => '{"id":"facebook-simple-product-group-item-id"}',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];
		$graph_api                            = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$graph_api->expects( $this->once() )
			->method( 'create_product_group' )
			->with(
				'1234567891011121314',
				[ 'retailer_id' => WC_Facebookcommerce_Utils::get_fb_retailer_id( $facebook_product ) ]
			)
			->willReturn( $facebook_output_create_product_group );
		$facebook_output_create_product_item = [
			'headers'  => [],
			'body'     => '{"id":"facebook-simple-product-group-item-id"}',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];
		$graph_api->expects( $this->once() )
			->method( 'create_product_item' )
			->with(
				'facebook-simple-product-group-item-id',
				$facebook_product->prepare_product( WC_Facebookcommerce_Utils::get_fb_retailer_id( $facebook_product ) ),
			)
			->willReturn( $facebook_output_create_product_item );
		$this->integration->fbgraph = $graph_api;

		$facebook_product_item_id = $this->integration->on_simple_product_publish( $product->get_id(), $facebook_product );

		$this->assertEquals( 'facebook-simple-product-group-item-id', $facebook_product_item_id );
		$this->assertEquals(
			'facebook-simple-product-group-item-id',
			get_post_meta( $facebook_product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true )
		);
	}

	/**
	 * Tests product should be synced calls to return success.
	 *
	 * @return void
	 */
	public function test_product_should_be_synced_calls_facebook_api() {
		$product = WC_Helper_Product::create_simple_product();

		$validator = $this->createMock( ProductValidator::class );
		$validator->expects( $this->once() )
			->method( 'validate' );
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_product_sync_validator' )
			->with( $product )
			->willReturn( $validator );

		$output = $this->integration->product_should_be_synced( $product );

		$this->assertTrue( $output );
	}

	/**
	 * Test product should be synced handles exception from facebook api and returns failure.
	 *
	 * @return void
	 */
	public function test_product_should_be_synced_calls_facebook_api_with_exception() {
		$product = WC_Helper_Product::create_simple_product();

		$validator = $this->createMock( ProductValidator::class );
		$validator->expects( $this->once() )
			->method( 'validate' )
			->will( $this->throwException( new Exception() ) );
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_product_sync_validator' )
			->with( $product )
			->willReturn( $validator );

		$output = $this->integration->product_should_be_synced( $product );

		$this->assertFalse( $output );
	}

	/**
	 * Tests create simple product creates product group and the product itself.
	 *
	 * @return void
	 */
	public function test_create_product_simple_creates_product_group_before_creating_product_item() {
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, '123456789101112' );

		$product          = WC_Helper_Product::create_simple_product();
		$facebook_product = new WC_Facebook_Product( $product->get_id() );
		$retailer_id      = WC_Facebookcommerce_Utils::get_fb_retailer_id( $facebook_product );

		$graph_api                            = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$data                                 = [
			'retailer_id' => $retailer_id,
		];
		$facebook_output_create_product_group = [
			'headers'  => [],
			'body'     => '{"id":"facebook-simple-product-group-id"}',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];
		$graph_api->expects( $this->once() )
			->method( 'create_product_group' )
			->with( '123456789101112', $data )
			->willReturn( $facebook_output_create_product_group );

		$data                                = $facebook_product->prepare_product( $retailer_id );
		$facebook_output_create_product_item = [
			'headers'  => [],
			'body'     => '{"id":"facebook-simple-product-item-id"}',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];
		$graph_api->expects( $this->once() )
			->method( 'create_product_item' )
			->with( 'facebook-simple-product-group-id', $data )
			->willReturn( $facebook_output_create_product_item );
		$this->integration->fbgraph = $graph_api;

		$facebook_product_item_id = $this->integration->create_product_simple( $facebook_product );

		$this->assertEquals( 'facebook-simple-product-group-id', get_post_meta( $facebook_product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
		$this->assertEquals( 'facebook-simple-product-item-id', get_post_meta( $facebook_product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
		$this->assertEquals( 'facebook-simple-product-item-id', $facebook_product_item_id );
	}

	/**
	 * Tests create simple product with provided product group id.
	 *
	 * @return void
	 */
	public function test_create_product_simple_creates_product_with_provided_product_group_id() {
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, '123456789101112' );

		$product          = WC_Helper_Product::create_simple_product();
		$facebook_product = new WC_Facebook_Product( $product->get_id() );
		$retailer_id      = WC_Facebookcommerce_Utils::get_fb_retailer_id( $facebook_product );

		$graph_api                           = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$data                                = $facebook_product->prepare_product( $retailer_id );
		$facebook_output_create_product_item = [
			'headers'  => [],
			'body'     => '{"id":"facebook-simple-product-item-id"}',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];
		$graph_api->expects( $this->once() )
			->method( 'create_product_item' )
			->with( 'facebook-simple-product-group-id', $data )
			->willReturn( $facebook_output_create_product_item );
		$this->integration->fbgraph = $graph_api;

		$facebook_product_item_id = $this->integration->create_product_simple( $facebook_product, 'facebook-simple-product-group-id' );

		$this->assertEquals( 'facebook-simple-product-item-id', get_post_meta( $facebook_product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
		$this->assertEquals( 'facebook-simple-product-item-id', $facebook_product_item_id );
	}

	/**
	 * Tests create simple product fails to create product group and returns empty product item id.
	 *
	 * @return void
	 */
	public function test_create_product_simple_with_failed_create_product_group_returns_empty_product_item() {
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, '123456789101112' );

		$product          = WC_Helper_Product::create_simple_product();
		$facebook_product = new WC_Facebook_Product( $product->get_id() );
		$retailer_id      = WC_Facebookcommerce_Utils::get_fb_retailer_id( $facebook_product );

		$graph_api                            = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$data                                 = [
			'retailer_id' => $retailer_id,
		];
		$facebook_output_create_product_group = [
			'headers'  => [],
			'body'     => '{"error":{"message":"Unsupported post request. Object with ID \'4964146013695812\' does not exist, cannot be loaded due to missing permissions, or does not support this operation. Please read the Graph API documentation at https:\/\/developers.facebook.com\/docs\/graph-api","type":"GraphMethodException","code":100,"error_subcode":33,"fbtrace_id":"AtmMkt0H2dwNBhdRfcYqzVY"}}',
			'response' => [
				'code'    => 400,
				'message' => 'OK',
			],
		];
		$graph_api->expects( $this->once() )
			->method( 'create_product_group' )
			->with( '123456789101112', $data )
			->willReturn( $facebook_output_create_product_group );
		$this->integration->fbgraph = $graph_api;

		$facebook_product_item_id = $this->integration->create_product_simple( $facebook_product );

		$this->assertEquals( '', get_post_meta( $facebook_product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
		$this->assertEquals( '', get_post_meta( $facebook_product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
		$this->assertEquals( '', $facebook_product_item_id );
	}

	/**
	 * Tests create product group fpr product w/o variants.
	 *
	 * @return void
	 */
	public function test_create_product_group_creates_group_no_variants() {
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, '123456789101112' );

		$product          = WC_Helper_Product::create_simple_product();
		$facebook_product = new WC_Facebook_Product( $product->get_id() );
		$retailer_id      = 'product-retailer-id';

		$graph_api                            = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$data                                 = [
			'retailer_id' => $retailer_id,
		];
		$facebook_output_create_product_group = [
			'headers'  => [],
			'body'     => '{"id":"facebook-product-group-id"}',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];
		$graph_api->expects( $this->once() )
			->method( 'create_product_group' )
			->with( '123456789101112', $data )
			->willReturn( $facebook_output_create_product_group );
		$this->integration->fbgraph = $graph_api;

		$facebook_product_group_id = $this->integration->create_product_group( $facebook_product, $retailer_id );

		$this->assertEquals( 'facebook-product-group-id', $facebook_product_group_id );
		$this->assertEquals( 'facebook-product-group-id', get_post_meta( $facebook_product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
	}

	/**
	 * Tests create product group for product with variants.
	 *
	 * @return void
	 */
	public function test_create_product_group_creates_group_with_variants() {
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, '123456789101112' );

		$product          = WC_Helper_Product::create_simple_product();
		$facebook_product = new WC_Facebook_Product( $product->get_id() );
		$retailer_id      = 'product-retailer-id';

		$graph_api                            = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$data                                 = [
			'retailer_id' => $retailer_id,
			'variants'    => $facebook_product->prepare_variants_for_group(),
		];
		$facebook_output_create_product_group = [
			'headers'  => [],
			'body'     => '{"id":"facebook-product-group-id"}',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];
		$graph_api->expects( $this->once() )
			->method( 'create_product_group' )
			->with( '123456789101112', $data )
			->willReturn( $facebook_output_create_product_group );
		$this->integration->fbgraph = $graph_api;

		$facebook_product_group_id = $this->integration->create_product_group( $facebook_product, $retailer_id, true );

		$this->assertEquals( 'facebook-product-group-id', $facebook_product_group_id );
		$this->assertEquals( 'facebook-product-group-id', get_post_meta( $facebook_product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
	}

	/**
	 * Tests create product group fails due to some facebook error and returns empty string.
	 *
	 * @return void
	 */
	public function test_create_product_group_fails_to_create_group() {
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, '123456789101112' );

		$product          = WC_Helper_Product::create_simple_product();
		$facebook_product = new WC_Facebook_Product( $product->get_id() );
		$retailer_id      = 'product-retailer-id';

		$graph_api                            = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$data                                 = [
			'retailer_id' => $retailer_id,
		];
		$facebook_output_create_product_group = [
			'headers'  => [],
			'body'     => '{"error":{"message":"Unsupported post request. Object with ID \'4964146013695812\' does not exist, cannot be loaded due to missing permissions, or does not support this operation. Please read the Graph API documentation at https:\/\/developers.facebook.com\/docs\/graph-api","type":"GraphMethodException","code":100,"error_subcode":33,"fbtrace_id":"AtmMkt0H2dwNBhdRfcYqzVY"}}',
			'response' => [
				'code'    => 400,
				'message' => 'OK',
			],
		];
		$graph_api->expects( $this->once() )
			->method( 'create_product_group' )
			->with( '123456789101112', $data )
			->willReturn( $facebook_output_create_product_group );
		$this->integration->fbgraph = $graph_api;

		$facebook_product_group_id = $this->integration->create_product_group( $facebook_product, $retailer_id );

		$this->assertEquals( '', $facebook_product_group_id );
	}

	/**
	 * Tests create product item creates product item and sets it into meta.
	 *
	 * @return void
	 */
	public function test_create_product_item_creates_product_item() {
		$product          = WC_Helper_Product::create_simple_product();
		$facebook_product = new WC_Facebook_Product( $product->get_id() );
		$product_group_id = 'facebook-product-group-id';
		$retailer_id      = 'product-retailer-id';

		$graph_api                           = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$data                                = $facebook_product->prepare_product( $retailer_id );
		$facebook_output_create_product_item = [
			'headers'  => [],
			'body'     => '{"id":"facebook-product-item-id"}',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];
		$graph_api->expects( $this->once() )
			->method( 'create_product_item' )
			->with( 'facebook-product-group-id', $data )
			->willReturn( $facebook_output_create_product_item );
		$this->integration->fbgraph = $graph_api;

		$facebook_item_id = $this->integration->create_product_item( $facebook_product, $retailer_id, $product_group_id );

		$this->assertEquals( 'facebook-product-item-id', $facebook_item_id );
		$this->assertEquals( 'facebook-product-item-id', get_post_meta( $facebook_product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
	}

	/**
	 * Tests create product item fails to create and returns empty string.
	 *
	 * @return void
	 */
	public function test_create_product_item_fails_to_create_product_item() {
		$product          = WC_Helper_Product::create_simple_product();
		$facebook_product = new WC_Facebook_Product( $product->get_id() );
		$product_group_id = 'facebook-product-group-id';
		$retailer_id      = 'product-retailer-id';

		$graph_api                           = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$data                                = $facebook_product->prepare_product( $retailer_id );
		$facebook_output_create_product_item = [
			'headers'  => [],
			'body'     => '{"error":{"message":"Unsupported post request. Object with ID \'4964146013695812\' does not exist, cannot be loaded due to missing permissions, or does not support this operation. Please read the Graph API documentation at https:\/\/developers.facebook.com\/docs\/graph-api","type":"GraphMethodException","code":100,"error_subcode":33,"fbtrace_id":"AtmMkt0H2dwNBhdRfcYqzVY"}}',
			'response' => [
				'code'    => 400,
				'message' => 'OK',
			],
		];
		$graph_api->expects( $this->once() )
			->method( 'create_product_item' )
			->with( 'facebook-product-group-id', $data )
			->willReturn( $facebook_output_create_product_item );
		$this->integration->fbgraph = $graph_api;

		$facebook_item_id = $this->integration->create_product_item( $facebook_product, $retailer_id, $product_group_id );

		$this->assertEquals( '', $facebook_item_id );
	}

	/**
	 * Tests update product group updates product group.
	 *
	 * @return void
	 */
	public function test_update_product_group_updates_product_group() {
		$product          = WC_Helper_Product::create_variation_product();
		$facebook_product = new WC_Facebook_Product( $product->get_id() );
		add_post_meta( $facebook_product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'facebook-product-group-id' );

		$graph_api = $this->createMock( WC_Facebookcommerce_Graph_API::class );

		$data                                 = [
			'variants' => $facebook_product->prepare_variants_for_group(),
		];
		$facebook_output_update_product_group = [
			'headers'  => [],
			'body'     => '{"id":"5191364664265911"}',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];
		$graph_api->expects( $this->once() )
			->method( 'update_product_group' )
			->with( 'facebook-product-group-id', $data )
			->willReturn( $facebook_output_update_product_group );
		$this->integration->fbgraph = $graph_api;

		$this->integration->update_product_group( $facebook_product );
	}

	/**
	 * Tests update product group exits when product group missing.
	 *
	 * @return void
	 */
	public function test_update_product_group_exits_when_product_group_missing() {
		$product          = WC_Helper_Product::create_variation_product();
		$facebook_product = new WC_Facebook_Product( $product->get_id() );

		$graph_api = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$graph_api->expects( $this->once() )
			->method( 'get_facebook_id' )
			->will( $this->throwException( new Exception() ) );
		$graph_api->expects( $this->never() )
			->method( 'update_product_group' );
		$this->integration->fbgraph = $graph_api;

		$this->integration->update_product_group( $facebook_product );
	}

	/**
	 * Tests update product group exits when product has no variants.
	 *
	 * @return void
	 */
	public function test_update_product_group_exits_when_product_variants_missing() {
		$product          = WC_Helper_Product::create_simple_product();
		$facebook_product = new WC_Facebook_Product( $product->get_id() );
		add_post_meta( $facebook_product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'facebook-product-group-id' );

		$graph_api = $this->createMock( WC_Facebookcommerce_Graph_API::class );

		$graph_api->expects( $this->never() )
			->method( 'update_product_group' );
		$this->integration->fbgraph = $graph_api;

		$this->integration->update_product_group( $facebook_product );
	}

	/**
	 * Tests update product item updates product item.
	 *
	 * @return void
	 */
	public function test_update_product_item_updates_product_item() {
		$product                  = WC_Helper_Product::create_simple_product();
		$facebook_product         = new WC_Facebook_Product( $product->get_id() );
		$facebook_product_item_id = 'facebook-product-item-id';

		$graph_api                           = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$data                                = $facebook_product->prepare_product();
		$data['additional_image_urls']       = '';
		$facebook_output_update_product_item = [
			'headers'  => [],
			'body'     => '{"id":"5191364664265911"}',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];
		$graph_api->expects( $this->once() )
			->method( 'update_product_item' )
			->with( 'facebook-product-item-id', $data )
			->willReturn( $facebook_output_update_product_item );
		$this->integration->fbgraph = $graph_api;

		$this->integration->update_product_item( $facebook_product, $facebook_product_item_id );
	}

	/**
	 * Tests product set item update.
	 *
	 * @return void
	 */
	public function test_create_or_update_product_set_item_updates_product_set_item() {
		$product_set_id   = '987654321';
		$product_set_data = [ 'some-product-set-data' ];

		add_term_meta( $product_set_id, WC_Facebookcommerce_Integration::FB_PRODUCT_SET_ID, 'facebook-product-set-id' );

		$facebook_output_update_product_set_item = [
			'headers'  => [],
			'body'     => '{"id":"5191364664265911"}',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];

		$graph_api = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$graph_api->expects( $this->once() )
			->method( 'update_product_set_item' )
			->with( 'facebook-product-set-id', $product_set_data )
			->willReturn( $facebook_output_update_product_set_item );
		$this->integration->fbgraph = $graph_api;

		$this->integration->create_or_update_product_set_item( $product_set_data, $product_set_id );
	}

	/**
	 * Tests product set item create.
	 *
	 * @return void
	 */
	public function test_create_or_update_product_set_item_creates_product_set_item() {
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, '123456789101112' );

		$product_set_id   = '987654321';
		$product_set_data = [ 'some-product-set-data' ];

		/* Make sure term meta is empty. */
		add_term_meta( $product_set_id, WC_Facebookcommerce_Integration::FB_PRODUCT_SET_ID, '' );

		$facebook_output_create_product_set_item = [
			'headers'  => [],
			'body'     => '{"id":"5191364664265911"}',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];

		$graph_api = $this->createMock( WC_Facebookcommerce_Graph_API::class );
		$graph_api->expects( $this->once() )
			->method( 'create_product_set_item' )
			->with( '123456789101112', $product_set_data )
			->willReturn( $facebook_output_create_product_set_item );
		$this->integration->fbgraph = $graph_api;

		$this->integration->create_or_update_product_set_item( $product_set_data, $product_set_id );

		$facebook_product_set_id = get_term_meta( $product_set_id, WC_Facebookcommerce_Integration::FB_PRODUCT_SET_ID, true );

		$this->assertEquals( '5191364664265911', $facebook_product_set_id );
	}

	/**
	 * Tests delete product set item performs facebook graph api request.
	 *
	 * @return void
	 */
	public function test_delete_product_set_item() {
		$facebook_product_set_id = 'facebook-product-set-id';

		$graph_api = $this->createMock( WC_Facebookcommerce_Graph_API::class );

		$facebook_output_delete_product_set_item = [
			'headers'  => [],
			'body'     => '', // Response does not matter, method does check response status code only.
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];

		$graph_api->expects( $this->once() )
			->method( 'delete_product_set_item' )
			->with( $facebook_product_set_id )
			->willReturn( $facebook_output_delete_product_set_item );
		$this->integration->fbgraph = $graph_api;

		$this->integration->delete_product_set_item( $facebook_product_set_id );
	}

	/**
	 * Tests transient message.
	 *
	 * @return void
	 */
	public function test_display_info_message() {
		$this->integration->display_info_message( 'Test message.' );

		$this->assertEquals( '<b>Facebook for WooCommerce</b><br/>Test message.', get_transient( 'facebook_plugin_api_info' ) );
	}

	/**
	 * Tests transient message.
	 *
	 * @return void
	 */
	public function test_display_sticky_message() {
		$this->integration->display_sticky_message( 'Test message.' );

		$this->assertEquals( '<b>Facebook for WooCommerce</b><br/>Test message.', get_transient( 'facebook_plugin_api_sticky' ) );
	}

	/**
	 * Tests transient message removal.
	 *
	 * @return void
	 */
	public function test_remove_sticky_message() {
		set_transient( 'facebook_plugin_api_sticky', 'Some test text.', 60 );

		$this->integration->remove_sticky_message();

		$this->assertFalse( get_transient( 'facebook_plugin_api_sticky' ) );
	}

	/**
	 * Tests remove re-sync message removes the message.
	 *
	 * @return void
	 */
	public function test_remove_resync_message_removes_the_message() {
		set_transient( 'facebook_plugin_api_sticky', 'Sync some test message.' );
		set_transient( 'facebook_plugin_resync_sticky', 'Some re-sync test message.' );

		$this->integration->remove_resync_message();

		$this->assertFalse( get_transient( 'facebook_plugin_resync_sticky' ) );
	}

	/**
	 * Tests remove re-sync message does not remove the message
	 * if facebook_plugin_api_sticky transient message does not
	 * start with 'Sync'.
	 *
	 * @return void
	 */
	public function test_remove_resync_message_does_not_remove_the_message_when_sticky_message_starts_with_no_sync() {
		set_transient( 'facebook_plugin_api_sticky', 'Some test message.' );
		set_transient( 'facebook_plugin_resync_sticky', 'Some re-sync test message.' );

		$this->integration->remove_resync_message();

		$this->assertEquals( 'Some re-sync test message.', get_transient( 'facebook_plugin_resync_sticky' ) );
	}

	/**
	 * Tests remove re-sync message does not remove the message
	 * if facebook_plugin_api_sticky transient message is empty.
	 *
	 * @return void
	 */
	public function test_remove_resync_message_does_not_remove_the_message_when_sticky_message_is_empty() {
		set_transient( 'facebook_plugin_api_sticky', '' );
		set_transient( 'facebook_plugin_resync_sticky', 'Some re-sync test message.' );

		$this->integration->remove_resync_message();

		$this->assertEquals( 'Some re-sync test message.', get_transient( 'facebook_plugin_resync_sticky' ) );
	}

	/**
	 * Tests display error message adds transient message.
	 *
	 * @return void
	 */
	public function test_display_error_message() {
		$this->integration->display_error_message( 'Hello, this is a test message.' );

		$this->assertEquals( 'Hello, this is a test message.', get_transient( 'facebook_plugin_api_error' ) );
	}

	/**
	 * Tests display error message from results when error message
	 * equals to 'Fatal' and a non-empty error_user_title.
	 *
	 * @return void
	 */
	public function test_display_error_message_from_result_error_message_equals_fatal_and_non_empty_error_user_title() {
		$data = [
			'body' => '{"error":{"message":"Fatal","error_user_title":"Non-empty error user title."}}',
		];
		$this->integration->display_error_message_from_result( $data );

		$this->assertEquals( 'Non-empty error user title.', get_transient( 'facebook_plugin_api_error' ) );
	}

	/**
	 * Tests display error message from results when error message
	 * equals to 'Fatal' and an empty error_user_title.
	 *
	 * @return void
	 */
	public function test_display_error_message_from_result_error_message_equals_fatal_and_an_empty_error_user_title() {
		$data = [
			'body' => '{"error":{"message":"Fatal","error_user_title":""}}',
		];
		$this->integration->display_error_message_from_result( $data );

		$this->assertEquals( 'Fatal', get_transient( 'facebook_plugin_api_error' ) );
	}

	/**
	 * Tests display error message from results when error message is not equal 'Fatal'.
	 *
	 * @return void
	 */
	public function test_display_error_message_from_result_error_message_not_equal_fatal() {
		$data = [
			'body' => '{"error":{"message":"Non-fatal","error_user_title":""}}',
		];
		$this->integration->display_error_message_from_result( $data );

		$this->assertEquals( 'Non-fatal', get_transient( 'facebook_plugin_api_error' ) );
	}

	/**
	 * Tests check api result function receives WP_Error input.
	 *
	 * @return void
	 */
	public function test_check_api_result_processes_wp_error() {
		$input = new WP_Error( 999, 'Some error text message.' );

		$this->integration->check_api_result( $input );

		$this->assertEquals( 'There was an issue connecting to the Facebook API:  Some error text message.', get_transient( 'facebook_plugin_api_error' ) );
	}

	/**
	 * Tests check api result function receives input with 200 status.
	 *
	 * @return void
	 */
	public function test_check_api_result_processes_200_status_input() {
		$input = [
			'headers'  => [],
			'body'     => '{"id":"5191364664265911"}',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];

		$output = $this->integration->check_api_result( $input );

		$this->assertEquals( $input, $output );
	}

	/**
	 * Tests check api result function receives input with non 200 status and some non 10800 error code.
	 *
	 * @return void
	 */
	public function test_check_api_result_processes_non_200_status_and_some_error_code_input() {
		$input = [
			'headers'  => [],
			'body'     => '{"error":{"code":9999,"message":"Fatal","error_user_title":"Some error user title here."}}',
			'response' => [
				'code'    => 1111,
				'message' => '',
			],
		];

		$output = $this->integration->check_api_result( $input );

		$this->assertNull( $output );
		$this->assertEquals( 'Some error user title here.', get_transient( 'facebook_plugin_api_error' ) );
	}

	/**
	 * Tests check api result function receives input with non 200 status and 10800 error code.
	 *
	 * @return void
	 */
	public function test_check_api_result_processes_non_200_status_and_10800_error_code_input() {
		$input = [
			'headers'  => [],
			'body'     => '{"error":{"code":10800,"message":"Fatal","error_user_title":"Some error user title here.","error_data":{"product_group_id":"987654321","product_item_id":"123456789"}}}',
			'response' => [
				'code'    => 1111,
				'message' => '',
			],
		];

		$expected = [
			'headers'  => [],
			'body'     => '{"error":{"code":10800,"message":"Fatal","error_user_title":"Some error user title here.","error_data":{"product_group_id":"987654321","product_item_id":"123456789"}},"id":"987654321"}',
			'response' => [
				'code'    => 1111,
				'message' => '',
			],
		];

		$output = $this->integration->check_api_result( $input, null, '112233445566' );

		$this->assertEquals( $expected, $output );
		$this->assertEquals( '987654321', get_post_meta( '112233445566', WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
	}

	/**
	 * Tests ajax_woo_adv_bulk_edit_compat for user with appropriate permissions.
	 *
	 * @return void
	 */
	public function test_ajax_woo_adv_bulk_edit_compat_for_user_with_appropriate_permissions() {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		set_current_screen( 'edit-post' );

		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_settings_url' )
			->willReturn( 'https://settings.site/settings.php' );

		$_POST['type'] = 'product';

		$this->integration->ajax_woo_adv_bulk_edit_compat( 'dummy' );

		$this->assertTrue( is_admin(), 'Current user must be an admin user.' );
		$this->assertEquals(
			'<b>Facebook for WooCommerce</b><br/>' .
			'Products may be out of Sync with Facebook due to your recent advanced bulk edit.' .
			' <a href="https://settings.site/settings.php&fb_force_resync=true&remove_sticky=true">Re-Sync them with FB.</a>',
			get_transient( 'facebook_plugin_api_sticky' )
		);
	}

	/**
	 * Tests ajax_woo_adv_bulk_edit_compat for user without appropriate permissions.
	 *
	 * @return void
	 */
	public function test_ajax_woo_adv_bulk_edit_compat_for_user_without_appropriate_permissions() {
		$this->facebook_for_woocommerce->expects( $this->never() )
			->method( 'get_settings_url' );

		$_POST['type'] = 'product';
		$this->integration->ajax_woo_adv_bulk_edit_compat( 'dummy' );
	}

	/**
	 * Tests display out of sync message sets a message with settings url.
	 *
	 * @return void
	 */
	public function test_display_out_of_sync_message() {
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_settings_url' )
			->willReturn( 'https://settings.site/settings.php' );

		$this->integration->display_out_of_sync_message( 'some text to insert into message' );

		$this->assertEquals(
			'<b>Facebook for WooCommerce</b><br/>' .
			'Products may be out of Sync with Facebook due to your recent some text to insert into message.' .
			' <a href="https://settings.site/settings.php&fb_force_resync=true&remove_sticky=true">Re-Sync them with FB.</a>',
			get_transient( 'facebook_plugin_api_sticky' )
		);
	}

	/**
	 * Tests get existing facebook id returns facebook product group id from error data.
	 *
	 * @return void
	 */
	public function test_get_existing_fbid_returns_product_group_id() {
		$product_id                   = 123456789;
		$error_data                   = new stdClass();
		$error_data->product_group_id = 'facebook-product-group-id';
		$error_data->product_item_id  = 'facebook-product-item-id';

		$facebook_id = $this->integration->get_existing_fbid( $error_data, $product_id );

		$this->assertEquals( 'facebook-product-group-id', $facebook_id );
		$this->assertEquals( 'facebook-product-group-id', get_post_meta( $product_id, WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
	}

	/**
	 * Tests get existing facebook id returns facebook product item id from error data.
	 *
	 * @return void
	 */
	public function test_get_existing_fbid_returns_product_item_id() {
		$product_id                  = 123456789;
		$error_data                  = new stdClass();
		$error_data->product_item_id = 'facebook-product-item-id';

		$facebook_id = $this->integration->get_existing_fbid( $error_data, $product_id );

		$this->assertEquals( 'facebook-product-item-id', $facebook_id );
		$this->assertEquals( 'facebook-product-item-id', get_post_meta( $product_id, WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
	}

	/**
	 * Tests get existing facebook id returns nothing and does nothing.
	 *
	 * @return void
	 */
	public function test_get_existing_fbid_returns_does_nothing() {
		$product_id = 123456789;
		$error_data = new stdClass();

		$this->integration->get_existing_fbid( $error_data, $product_id );

		$this->assertEmpty( get_post_meta( $product_id, WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
		$this->assertEmpty( get_post_meta( $product_id, WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
	}

	/**
	 * Tests display of error messages depending on GET parameter present and its value.
	 *
	 * @return void
	 */
	public function test_checks_triggers_display_errors() {
		$_GET['page'] = 'wc-facebook';

		$this->integration->errors = [
			'Some error message one.',
			'Some error message two.',
		];

		set_transient( 'facebook_plugin_api_error', 'Facebook plugin api error message text.' );
		set_transient( 'facebook_plugin_api_warning', 'Facebook plugin api warning text.' );
		set_transient( 'facebook_plugin_api_success', 'Facebook plugin api success text.' );
		set_transient( 'facebook_plugin_api_info', 'Facebook plugin api info text.' );
		set_transient( 'facebook_plugin_api_sticky', 'Facebook plugin api sticky text.' );

		ob_start();
		$this->integration->checks();
		$output = ob_get_clean();

		/* Check produced function output expected. */
		$this->assertEquals(
			'<div id="woocommerce_errors" class="error notice is-dismissible"><p>Some error message one.</p><p>Some error message two.</p></div><div class="notice is-dismissible notice-error"><p><strong>Facebook for WooCommerce error:</strong></br>Facebook plugin api error message text.</p></div><div class="notice is-dismissible notice-warning"><p>Facebook plugin api warning text.</p></div><div class="notice is-dismissible notice-success"><p>Facebook plugin api success text.</p></div><div class="notice is-dismissible notice-info"><p>Facebook plugin api info text.</p></div><div class="notice is-dismissible notice-info"><p>Facebook plugin api sticky text.</p></div>',
			$output
		);
	}

	/**
	 * Tests does display facebook api messages, no errors.
	 *
	 * @return void
	 */
	public function test_checks_does_not_trigger_display_errors() {
		set_transient( 'facebook_plugin_api_error', 'Facebook plugin api error message text.' );
		set_transient( 'facebook_plugin_api_warning', 'Facebook plugin api warning text.' );
		set_transient( 'facebook_plugin_api_success', 'Facebook plugin api success text.' );
		set_transient( 'facebook_plugin_api_info', 'Facebook plugin api info text.' );
		set_transient( 'facebook_plugin_api_sticky', 'Facebook plugin api sticky text.' );

		ob_start();
		$this->integration->checks();
		$output = ob_get_clean();

		/* Check produced function output expected. */
		$this->assertEquals(
			'<div class="notice is-dismissible notice-error"><p><strong>Facebook for WooCommerce error:</strong></br>Facebook plugin api error message text.</p></div><div class="notice is-dismissible notice-warning"><p>Facebook plugin api warning text.</p></div><div class="notice is-dismissible notice-success"><p>Facebook plugin api success text.</p></div><div class="notice is-dismissible notice-info"><p>Facebook plugin api info text.</p></div><div class="notice is-dismissible notice-info"><p>Facebook plugin api sticky text.</p></div>',
			$output
		);
	}

	/**
	 * Tests get_sample_product_feed to return proper JSON with 12 recent products.
	 *
	 * @return void
	 */
	public function test_get_sample_product_feed_with_twelve_recent_products() {

		/* Generate 13 products. */
		array_map(
			function ( $index ) {
				/** @var WC_Product_Simple $product */
				$product = WC_Helper_Product::create_simple_product();
				$product->set_name( 'Test product ' . ( $index + 1 ) );
				$product->save();
			},
			array_keys( array_fill( 0, 13, true ) )
		);

		/* Feed of 12 recent products. */
		$json = $this->integration->get_sample_product_feed();

		/* 12 recent products with product titled "Test product 1" missing from the feed. */
		$this->assertEquals( '[[{"title":"Test product 13","availability":"in stock","description":"Test product 13","id":"wc_post_id_22","image_link":"http:\/\/example.org\/wp-content\/plugins\/Users\/dimka\/Codebase\/automattic\/wordpress-facebook\/wp-content\/plugins\/facebook-for-woocommerce\/assets\/images\/woocommerce-placeholder.png","brand":"Test Blog","link":"http:\/\/example.org\/?product=dummy-product-13","price":"1000 USD"},{"title":"Test product 12","availability":"in stock","description":"Test product 12","id":"wc_post_id_21","image_link":"http:\/\/example.org\/wp-content\/plugins\/Users\/dimka\/Codebase\/automattic\/wordpress-facebook\/wp-content\/plugins\/facebook-for-woocommerce\/assets\/images\/woocommerce-placeholder.png","brand":"Test Blog","link":"http:\/\/example.org\/?product=dummy-product-12","price":"1000 USD"},{"title":"Test product 11","availability":"in stock","description":"Test product 11","id":"wc_post_id_20","image_link":"http:\/\/example.org\/wp-content\/plugins\/Users\/dimka\/Codebase\/automattic\/wordpress-facebook\/wp-content\/plugins\/facebook-for-woocommerce\/assets\/images\/woocommerce-placeholder.png","brand":"Test Blog","link":"http:\/\/example.org\/?product=dummy-product-11","price":"1000 USD"},{"title":"Test product 10","availability":"in stock","description":"Test product 10","id":"wc_post_id_19","image_link":"http:\/\/example.org\/wp-content\/plugins\/Users\/dimka\/Codebase\/automattic\/wordpress-facebook\/wp-content\/plugins\/facebook-for-woocommerce\/assets\/images\/woocommerce-placeholder.png","brand":"Test Blog","link":"http:\/\/example.org\/?product=dummy-product-10","price":"1000 USD"},{"title":"Test product 9","availability":"in stock","description":"Test product 9","id":"wc_post_id_18","image_link":"http:\/\/example.org\/wp-content\/plugins\/Users\/dimka\/Codebase\/automattic\/wordpress-facebook\/wp-content\/plugins\/facebook-for-woocommerce\/assets\/images\/woocommerce-placeholder.png","brand":"Test Blog","link":"http:\/\/example.org\/?product=dummy-product-9","price":"1000 USD"},{"title":"Test product 8","availability":"in stock","description":"Test product 8","id":"wc_post_id_17","image_link":"http:\/\/example.org\/wp-content\/plugins\/Users\/dimka\/Codebase\/automattic\/wordpress-facebook\/wp-content\/plugins\/facebook-for-woocommerce\/assets\/images\/woocommerce-placeholder.png","brand":"Test Blog","link":"http:\/\/example.org\/?product=dummy-product-8","price":"1000 USD"},{"title":"Test product 7","availability":"in stock","description":"Test product 7","id":"wc_post_id_16","image_link":"http:\/\/example.org\/wp-content\/plugins\/Users\/dimka\/Codebase\/automattic\/wordpress-facebook\/wp-content\/plugins\/facebook-for-woocommerce\/assets\/images\/woocommerce-placeholder.png","brand":"Test Blog","link":"http:\/\/example.org\/?product=dummy-product-7","price":"1000 USD"},{"title":"Test product 6","availability":"in stock","description":"Test product 6","id":"wc_post_id_15","image_link":"http:\/\/example.org\/wp-content\/plugins\/Users\/dimka\/Codebase\/automattic\/wordpress-facebook\/wp-content\/plugins\/facebook-for-woocommerce\/assets\/images\/woocommerce-placeholder.png","brand":"Test Blog","link":"http:\/\/example.org\/?product=dummy-product-6","price":"1000 USD"},{"title":"Test product 5","availability":"in stock","description":"Test product 5","id":"wc_post_id_14","image_link":"http:\/\/example.org\/wp-content\/plugins\/Users\/dimka\/Codebase\/automattic\/wordpress-facebook\/wp-content\/plugins\/facebook-for-woocommerce\/assets\/images\/woocommerce-placeholder.png","brand":"Test Blog","link":"http:\/\/example.org\/?product=dummy-product-5","price":"1000 USD"},{"title":"Test product 4","availability":"in stock","description":"Test product 4","id":"wc_post_id_13","image_link":"http:\/\/example.org\/wp-content\/plugins\/Users\/dimka\/Codebase\/automattic\/wordpress-facebook\/wp-content\/plugins\/facebook-for-woocommerce\/assets\/images\/woocommerce-placeholder.png","brand":"Test Blog","link":"http:\/\/example.org\/?product=dummy-product-4","price":"1000 USD"},{"title":"Test product 3","availability":"in stock","description":"Test product 3","id":"wc_post_id_12","image_link":"http:\/\/example.org\/wp-content\/plugins\/Users\/dimka\/Codebase\/automattic\/wordpress-facebook\/wp-content\/plugins\/facebook-for-woocommerce\/assets\/images\/woocommerce-placeholder.png","brand":"Test Blog","link":"http:\/\/example.org\/?product=dummy-product-3","price":"1000 USD"},{"title":"Test product 2","availability":"in stock","description":"Test product 2","id":"wc_post_id_11","image_link":"http:\/\/example.org\/wp-content\/plugins\/Users\/dimka\/Codebase\/automattic\/wordpress-facebook\/wp-content\/plugins\/facebook-for-woocommerce\/assets\/images\/woocommerce-placeholder.png","brand":"Test Blog","link":"http:\/\/example.org\/?product=dummy-product-2","price":"1000 USD"}]]', $json );
		$this->assertCount( 12, current( json_decode( $json ) ) );
	}

	/**
	 * Tests delete post meta loop deletes meta for the given products.
	 *
	 * @return void
	 */
	public function test_delete_post_meta_loop() {
		/* Generate 13 products. */
		$products = array_map(
			function ( $index ) {
				/** @var WC_Product_Simple $product */
				$product = WC_Helper_Product::create_simple_product();
				$product->set_name( 'Test product ' . ( $index + 1 ) );
				$product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'facebook-product-group-id-' . ( $index + 1 ) );
				$product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'facebook-product-item-id-' . ( $index + 1 ) );
				$product->add_meta_data( Products::VISIBILITY_META_KEY, true );
				$product->save();

				return $product->get_id();
			},
			array_keys( array_fill( 0, 3, true ) )
		);

		$this->integration->delete_post_meta_loop( $products );

		$this->assertEquals( '', get_post_meta( $products[0], WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
		$this->assertEquals( '', get_post_meta( $products[1], WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
		$this->assertEquals( '', get_post_meta( $products[2], WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );

		$this->assertEquals( '', get_post_meta( $products[0], WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
		$this->assertEquals( '', get_post_meta( $products[1], WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
		$this->assertEquals( '', get_post_meta( $products[2], WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );

		$this->assertEquals( '', get_post_meta( $products[0], Products::VISIBILITY_META_KEY, true ) );
		$this->assertEquals( '', get_post_meta( $products[1], Products::VISIBILITY_META_KEY, true ) );
		$this->assertEquals( '', get_post_meta( $products[2], Products::VISIBILITY_META_KEY, true ) );
	}

	/**
	 * Tests reset all product facebook meta does nothing is not an admin user.
	 *
	 * @return void
	 */
	public function test_reset_all_products_as_non_admin_user() {
		$this->assertFalse( $this->integration->reset_all_products() );
		$this->assertFalse( is_admin() );
	}

	/**
	 * Tests reset all product facebook meta, including variation products if any.
	 *
	 * @return void
	 */
	public function test_reset_all_products_as_admin_user() {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		set_current_screen( 'edit-post' );

		/** @var WC_Product_Simple $product */
		$product = WC_Helper_Product::create_simple_product();
		$product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'facebook-product-group-id-1' );
		$product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'facebook-product-item-id-1' );
		$product->add_meta_data( Products::VISIBILITY_META_KEY, true );
		$product->save();

		/** @var WC_Product_Variable $variable_product */
		$variable_product = WC_Helper_Product::create_variation_product();
		$variable_product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'facebook-product-group-id-2' );
		$variable_product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'facebook-product-item-id-2' );
		$variable_product->add_meta_data( Products::VISIBILITY_META_KEY, true );
		$variable_product->save();

		$result = $this->integration->reset_all_products();

		$this->assertTrue( $result );
		$this->assertTrue( is_admin() );

		$this->assertEquals( '', get_post_meta( $product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
		$this->assertEquals( '', get_post_meta( $product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
		$this->assertEquals( '', get_post_meta( $product->get_id(), Products::VISIBILITY_META_KEY, true ) );

		$this->assertEquals( '', get_post_meta( $variable_product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
		$this->assertEquals( '', get_post_meta( $variable_product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
		$this->assertEquals( '', get_post_meta( $variable_product->get_id(), Products::VISIBILITY_META_KEY, true ) );

		foreach ( $variable_product->get_children() as $id ) {
			$this->assertEquals( '', get_post_meta( $id, WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
			$this->assertEquals( '', get_post_meta( $id, WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
			$this->assertEquals( '', get_post_meta( $id, Products::VISIBILITY_META_KEY, true ) );
		}
	}

	/**
	 * Tests reset of facebook group and item ids from simple product's metadata.
	 *
	 * @return void
	 */
	public function test_reset_single_product_for_simple_product() {
		/** @var WC_Product_Simple $product */
		$product = WC_Helper_Product::create_simple_product();
		$product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'facebook-product-group-id-1' );
		$product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'facebook-product-item-id-1' );
		$product->add_meta_data( Products::VISIBILITY_META_KEY, true );
		$product->save();

		$this->integration->reset_single_product( $product->get_id() );

		$this->assertEquals( '', get_post_meta( $product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
		$this->assertEquals( '', get_post_meta( $product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
		$this->assertEquals( '', get_post_meta( $product->get_id(), Products::VISIBILITY_META_KEY, true ) );
	}

	/**
	 * Tests reset of facebook group and item ids from variable product's metadata.
	 *
	 * @return void
	 */
	public function test_reset_single_product_for_variable_product() {
		/** @var WC_Product_Variable $variable_product */
		$variable_product = WC_Helper_Product::create_variation_product();
		$variable_product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'facebook-product-group-id-2' );
		$variable_product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'facebook-product-item-id-2' );
		$variable_product->add_meta_data( Products::VISIBILITY_META_KEY, true );
		$variable_product->save();

		$this->integration->reset_single_product( $variable_product->get_id() );

		$this->assertEquals( '', get_post_meta( $variable_product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
		$this->assertEquals( '', get_post_meta( $variable_product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
		$this->assertEquals( '', get_post_meta( $variable_product->get_id(), Products::VISIBILITY_META_KEY, true ) );

		foreach ( $variable_product->get_children() as $id ) {
			$this->assertEquals( '', get_post_meta( $id, WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
			$this->assertEquals( '', get_post_meta( $id, WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
			$this->assertEquals( '', get_post_meta( $id, Products::VISIBILITY_META_KEY, true ) );
		}
	}
}
