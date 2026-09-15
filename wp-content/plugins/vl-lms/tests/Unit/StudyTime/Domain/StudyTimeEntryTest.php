<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\StudyTime\Domain;

use PHPUnit\Framework\TestCase;
use VL\LMS\StudyTime\Domain\StudyKind;
use VL\LMS\StudyTime\Domain\StudyTimeEntry;

final class StudyTimeEntryTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private static function row( string $kind = 'reading' ): array {
		return [
			'id'              => '9',
			'user_id'         => '7',
			'course_id'       => '42',
			'entity_type'     => 'topic',
			'entity_id'       => '101',
			'kind'            => $kind,
			'active_seconds'  => '75',
			'first_signal_at' => '2026-09-15 10:00:00',
			'last_signal_at'  => '2026-09-15 10:01:15',
		];
	}

	public function test_from_row_casts_numeric_strings_and_maps_kind(): void {
		$entry = StudyTimeEntry::from_row( self::row() );

		self::assertSame( 9, $entry->id );
		self::assertSame( 7, $entry->user_id );
		self::assertSame( 42, $entry->course_id );
		self::assertSame( 'topic', $entry->entity_type );
		self::assertSame( 101, $entry->entity_id );
		self::assertSame( StudyKind::READING, $entry->kind );
		self::assertSame( 75, $entry->active_seconds );
	}

	public function test_from_row_parses_both_signal_columns_as_utc(): void {
		$entry = StudyTimeEntry::from_row( self::row() );

		self::assertSame( 'UTC', $entry->first_signal_at->getTimezone()->getName() );
		self::assertSame( '2026-09-15T10:00:00+00:00', $entry->first_signal_at->format( DATE_ATOM ) );
		self::assertSame( '2026-09-15T10:01:15+00:00', $entry->last_signal_at->format( DATE_ATOM ) );
	}

	public function test_from_row_rejects_an_unknown_kind(): void {
		$this->expectException( \ValueError::class );

		StudyTimeEntry::from_row( self::row( 'quiz' ) );
	}
}
