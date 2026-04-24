<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Auth\Registration;

use PHPUnit\Framework\TestCase;
use VL\LMS\Auth\AccountKind;
use VL\LMS\Auth\Registration\RegistrationException;
use VL\LMS\Auth\Registration\RegistrationRequest;

final class RegistrationRequestTest extends TestCase {

	public function test_constructor_accepts_valid_input(): void {
		$request = new RegistrationRequest(
			email: 'someone@example.test',
			password: 'hunter2hunter2',
			first_name: 'Alice',
			last_name: 'Smith'
		);

		self::assertSame( 'someone@example.test', $request->email );
		self::assertSame( 'Alice', $request->first_name );
		self::assertSame( AccountKind::STUDENT, $request->account_kind );
	}

	public function test_constructor_accepts_explicit_student_kind(): void {
		$request = new RegistrationRequest(
			email: 'someone@example.test',
			password: 'x',
			first_name: 'Alice',
			last_name: 'Smith',
			account_kind: AccountKind::STUDENT
		);

		self::assertSame( AccountKind::STUDENT, $request->account_kind );
	}

	public function test_empty_email_throws(): void {
		$this->expectException( RegistrationException::class );
		$this->expectExceptionMessage( 'Email is required.' );

		new RegistrationRequest(
			email: '   ',
			password: 'hunter2hunter2',
			first_name: 'Alice',
			last_name: 'Smith'
		);
	}

	public function test_empty_password_throws(): void {
		$this->expectException( RegistrationException::class );

		new RegistrationRequest(
			email: 'someone@example.test',
			password: '',
			first_name: 'Alice',
			last_name: 'Smith'
		);
	}

	public function test_empty_first_name_throws(): void {
		$this->expectException( RegistrationException::class );

		new RegistrationRequest(
			email: 'someone@example.test',
			password: 'hunter2hunter2',
			first_name: '',
			last_name: 'Smith'
		);
	}

	public function test_empty_last_name_throws(): void {
		$this->expectException( RegistrationException::class );

		new RegistrationRequest(
			email: 'someone@example.test',
			password: 'hunter2hunter2',
			first_name: 'Alice',
			last_name: '  '
		);
	}

	public function test_unknown_account_kind_throws_with_known_code(): void {
		try {
			new RegistrationRequest(
				email: 'someone@example.test',
				password: 'hunter2hunter2',
				first_name: 'Alice',
				last_name: 'Smith',
				account_kind: 'moderator'
			);
			self::fail( 'Expected RegistrationException for unknown account_kind.' );
		} catch ( RegistrationException $e ) {
			self::assertSame( 'vl_lms_invalid_account_kind', $e->error_code() );
			self::assertSame( 400, $e->status_code() );
		}
	}
}
