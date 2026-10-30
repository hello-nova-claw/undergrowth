<?php
/**
 * Tests for the demo blog's WXR files.
 *
 * @package Undergrowth
 */

/**
 * The demo files are built by bin/build-demo-content and committed. These
 * tests read the committed files, so a build that breaks the rules the
 * Garden feed depends on is caught without running the builder.
 */
class Test_Demo_Content extends WP_UnitTestCase {

	/**
	 * The two demo files, by name.
	 *
	 * @return array<string, array{string}>
	 */
	public function demo_files(): array {
		return array(
			'small' => array( 'undergrowth-demo-small.xml' ),
			'full'  => array( 'undergrowth-demo.xml' ),
		);
	}

	/**
	 * Reads one demo file.
	 *
	 * @param string $file File name under demo/.
	 * @return array{posts: array<int, array{formats: string[], thumbnail: string, status: string}>, attachments: int[]}
	 */
	private function read( string $file ): array {
		$xml = simplexml_load_file( dirname( UNDERGROWTH_FILE ) . '/demo/' . $file );

		$this->assertInstanceOf( SimpleXMLElement::class, $xml, "$file is well-formed XML" );

		$posts       = array();
		$attachments = array();

		foreach ( $xml->channel->item as $item ) {
			$wp   = $item->children( 'wp', true );
			$type = (string) $wp->post_type;

			if ( 'attachment' === $type ) {
				$attachments[] = (int) $wp->post_id;
				continue;
			}

			if ( 'post' !== $type ) {
				continue;
			}

			$formats   = array();
			$thumbnail = '';

			foreach ( $item->category as $term ) {
				if ( 'post_format' === (string) $term['domain'] ) {
					$formats[] = (string) $term['nicename'];
				}
			}

			foreach ( $wp->postmeta as $meta ) {
				if ( '_thumbnail_id' === (string) $meta->meta_key ) {
					$thumbnail = (string) $meta->meta_value;
				}
			}

			$posts[ (int) $wp->post_id ] = array(
				'formats'   => $formats,
				'thumbnail' => $thumbnail,
				'status'    => (string) $wp->status,
			);
		}

		return array(
			'posts'       => $posts,
			'attachments' => $attachments,
		);
	}

	/**
	 * A post has a format or a picture, never both and never neither.
	 *
	 * @dataProvider demo_files
	 *
	 * @param string $file File name under demo/.
	 */
	public function test_a_post_has_a_format_or_a_picture_never_both( string $file ) {
		$demo = $this->read( $file );

		$this->assertNotEmpty( $demo['posts'] );

		foreach ( $demo['posts'] as $id => $post ) {
			if ( array() === $post['formats'] ) {
				$this->assertNotSame( '', $post['thumbnail'], "post $id has no format, so it has a picture" );
			} else {
				$this->assertCount( 1, $post['formats'], "post $id has one format" );
				$this->assertSame( '', $post['thumbnail'], "post $id has a format, so it has no picture" );
			}
		}
	}

	/**
	 * About one post in four has one of the four text formats.
	 *
	 * @dataProvider demo_files
	 *
	 * @param string $file File name under demo/.
	 */
	public function test_about_one_post_in_four_has_a_core_format( string $file ) {
		$demo    = $this->read( $file );
		$formats = array();

		foreach ( $demo['posts'] as $post ) {
			foreach ( $post['formats'] as $format ) {
				$formats[] = $format;
			}
		}

		$share = count( $formats ) / count( $demo['posts'] );

		$this->assertGreaterThan( 1 / 6, $share );
		$this->assertLessThan( 1 / 3, $share );
		$this->assertSame(
			array(),
			array_diff( array_unique( $formats ), array( 'post-format-aside', 'post-format-link', 'post-format-quote', 'post-format-status' ) ),
			'only the four text formats are used'
		);
	}

	/**
	 * The feed's first page of the small file holds every format.
	 */
	public function test_the_small_file_shows_all_four_formats_among_its_newest_posts() {
		$xml   = simplexml_load_file( dirname( UNDERGROWTH_FILE ) . '/demo/undergrowth-demo-small.xml' );
		$dated = array();

		foreach ( $xml->channel->item as $item ) {
			$wp = $item->children( 'wp', true );

			if ( 'post' !== (string) $wp->post_type || 'publish' !== (string) $wp->status ) {
				continue;
			}

			$format = '';

			foreach ( $item->category as $term ) {
				if ( 'post_format' === (string) $term['domain'] ) {
					$format = (string) $term['nicename'];
				}
			}

			$dated[ (string) $wp->post_date_gmt ] = $format;
		}

		krsort( $dated );

		// The feed's first page, twenty posts, holds every format and at
		// least one post with no format, which has a picture instead. The
		// feed's default order is newest tended first, by modified time, and
		// the importer gives each post its date as its modified time, since
		// the files carry none, so the newest twenty by date are that page.
		$first_page_posts = array_slice( $dated, 0, 20 );
		$first_page       = array_filter( $first_page_posts );

		$this->assertContains( '', $first_page_posts, 'the first page includes a picture' );
		$this->assertEqualSets(
			array( 'post-format-aside', 'post-format-link', 'post-format-quote', 'post-format-status' ),
			array_values( array_unique( $first_page ) )
		);
	}

	/**
	 * Pictures and attachments match one to one.
	 *
	 * @dataProvider demo_files
	 *
	 * @param string $file File name under demo/.
	 */
	public function test_every_picture_is_an_attachment_in_the_file_and_every_attachment_is_used( string $file ) {
		$demo = $this->read( $file );
		$used = array();

		foreach ( $demo['posts'] as $id => $post ) {
			if ( '' !== $post['thumbnail'] ) {
				$this->assertContains( (int) $post['thumbnail'], $demo['attachments'], "post $id's picture is an attachment in the file" );
				$used[] = (int) $post['thumbnail'];
			}
		}

		$this->assertEqualSets( $demo['attachments'], array_unique( $used ), 'no attachment is left unused' );
	}
}
