<?php
/* Endpoint Category Tests */

class Micropub_Endpoint_Categories_Test extends Micropub_UnitTestCase {

	/**
	 * A person tag as OwnYourSwarm sends it on a check-in.
	 */
	protected function h_card( $properties ) {
		return array(
			'type'       => array( 'h-card' ),
			'properties' => $properties,
		);
	}

	protected function create_with_categories( $categories ) {
		$request = self::create_json_request(
			array(
				'type'       => array( 'h-entry' ),
				'properties' => array(
					'content'  => array( 'checked in' ),
					'category' => $categories,
				),
			)
		);

		$response = $this->dispatch( $request, static::$author_id );
		$this->assertEquals( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$posts = get_posts( array( 'post_type' => 'post' ) );
		$this->assertCount( 1, $posts );

		return $posts[0]->ID;
	}

	protected function tag_names( $post_id ) {
		return wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );
	}

	protected function category_names( $post_id ) {
		return wp_get_post_categories( $post_id, array( 'fields' => 'names' ) );
	}

	/**
	 * The h-card used to reach trim() inside wp_set_object_terms(), which raised a
	 * TypeError on PHP 8 after the row was written but before meta_input was
	 * stored. The post survived without any mf2_* value and the request died.
	 */
	public function test_an_h_card_category_tags_the_person_by_url() {
		$card = $this->h_card(
			array(
				'name' => array( 'Marc' ),
				'url'  => array( 'https://example.com/marc' ),
			)
		);

		$post_id = $this->create_with_categories( array( $card ) );

		$this->assertEquals( array( 'https://example.com/marc' ), $this->tag_names( $post_id ) );
	}

	/**
	 * store_mf2() builds the meta from the original input, so the person stays
	 * recorded in full even though the term is only their URL.
	 */
	public function test_an_h_card_category_is_kept_in_full_in_the_meta() {
		$card = $this->h_card(
			array(
				'name' => array( 'Marc' ),
				'url'  => array( 'https://example.com/marc' ),
			)
		);

		$post_id = $this->create_with_categories( array( $card ) );

		$this->assertEquals( array( $card ), get_post_meta( $post_id, 'mf2_category', true ) );
	}

	public function test_an_h_card_category_without_a_url_yields_no_term() {
		$post_id = $this->create_with_categories(
			array( $this->h_card( array( 'name' => array( 'Marc' ) ) ) )
		);

		$this->assertSame( array(), $this->tag_names( $post_id ) );
	}

	public function test_a_nested_object_of_another_kind_yields_no_term() {
		$post_id = $this->create_with_categories(
			array(
				array(
					'type'       => array( 'h-adr' ),
					'properties' => array( 'locality' => array( 'Cologne' ) ),
				),
			)
		);

		$this->assertSame( array(), $this->tag_names( $post_id ) );
	}

	public function test_an_h_card_with_an_empty_url_yields_no_term() {
		$post_id = $this->create_with_categories(
			array( $this->h_card( array( 'url' => array( '' ) ) ) )
		);

		$this->assertSame( array(), $this->tag_names( $post_id ) );
	}

	public function test_string_categories_are_still_tagged() {
		$post_id = $this->create_with_categories( array( 'tag1', 'tag2' ) );

		$this->assertEqualSets( array( 'tag1', 'tag2' ), $this->tag_names( $post_id ) );
	}

	/**
	 * A category that matches an existing WordPress category by slug becomes a
	 * category rather than a tag.
	 */
	public function test_an_existing_category_slug_becomes_a_category() {
		self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Reviews',
				'slug'     => 'reviews',
			)
		);

		$post_id = $this->create_with_categories( array( 'reviews' ) );

		$this->assertContains( 'Reviews', $this->category_names( $post_id ) );
		$this->assertSame( array(), $this->tag_names( $post_id ) );
	}

	/**
	 * A check-in carries the venue as a string and the people as h-cards.
	 */
	public function test_strings_and_h_cards_can_be_mixed() {
		$post_id = $this->create_with_categories(
			array(
				'coffee',
				$this->h_card(
					array(
						'name' => array( 'Marc' ),
						'url'  => array( 'https://example.com/marc' ),
					)
				),
			)
		);

		$this->assertEqualSets(
			array( 'coffee', 'https://example.com/marc' ),
			$this->tag_names( $post_id )
		);
	}

	/**
	 * The TypeError fired inside wp_insert_post() after the row was written but
	 * before meta_input was stored, so the geo values went missing too.
	 */
	public function test_an_h_card_category_does_not_cost_the_other_metadata() {
		$request = self::create_json_request(
			array(
				'type'       => array( 'h-entry' ),
				'properties' => array(
					'content'  => array( 'checked in' ),
					'location' => array( 'geo:42.361,-71.092;u=25000' ),
					'category' => array(
						$this->h_card( array( 'url' => array( 'https://example.com/marc' ) ) ),
					),
				),
			)
		);

		$response = $this->dispatch( $request, static::$author_id );
		$this->assertEquals( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$posts   = get_posts( array( 'post_type' => 'post' ) );
		$post_id = $posts[0]->ID;

		$this->assertEquals( '42.361', get_post_meta( $post_id, 'geo_latitude', true ) );
		$this->assertEquals( '-71.092', get_post_meta( $post_id, 'geo_longitude', true ) );
	}
}
