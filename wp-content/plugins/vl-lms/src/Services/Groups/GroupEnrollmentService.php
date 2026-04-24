<?php

declare(strict_types=1);

namespace VL\LMS\Services\Groups;

use VL\LMS\Domain\Enrollment\EnrollmentSource;
use VL\LMS\Domain\Group\AccessEntityType;
use VL\LMS\Domain\Group\GroupAccess;
use VL\LMS\Domain\Group\GroupMember;
use VL\LMS\Repositories\EnrollmentRepository;
use VL\LMS\Repositories\GroupAccessRepository;
use VL\LMS\Repositories\GroupMemberRepository;
use VL\LMS\Services\Enrollment\EnrollmentService;

/**
 * Fans out group-level access/membership changes to individual
 * `vl_enrollments` rows.
 *
 * Four trigger methods mirror the four domain events. Each one walks the
 * relevant members/access relation and delegates per-row work to
 * {@see EnrollmentService::enroll()} (for grants) or a direct repository
 * update (for expiries). We deliberately do NOT call
 * `EnrollmentService::revoke()` when a group pathway disappears —
 * revocation means "administratively stripped" and carries a reason; a
 * user losing group access simply has their enrollment expire, so other
 * acquisition paths (purchase, manual) remain intact.
 *
 * Only `COURSE` entity types fan out in Phase 1. Webinar fan-out lands in
 * Phase 3 when webinar enrollment exists.
 *
 * Stateless — safe to construct per-request.
 *
 * @author Tymofii Synianskyi
 */
final class GroupEnrollmentService {

	public function __construct(
		private readonly GroupMemberRepository $members,
		private readonly GroupAccessRepository $access,
		private readonly EnrollmentRepository $enrollments,
		private readonly EnrollmentService $enrollment_service
	) {
	}

	/**
	 * Group gained access to an entity → enroll every active member.
	 */
	public function on_access_granted( GroupAccess $access ): void {
		if ( AccessEntityType::COURSE !== $access->entity_type ) {
			return;
		}

		foreach ( $this->members->list_active_members( $access->group_id ) as $member ) {
			$this->enrollment_service->enroll(
				$member->user_id,
				$access->entity_id,
				EnrollmentSource::GROUP,
				$access->group_id
			);
		}
	}

	/**
	 * Member joined a group → enroll them into every active course-access row.
	 */
	public function on_member_added( GroupMember $member ): void {
		foreach ( $this->access->list_active_for_group( $member->group_id ) as $access_row ) {
			if ( AccessEntityType::COURSE !== $access_row->entity_type ) {
				continue;
			}
			$this->enrollment_service->enroll(
				$member->user_id,
				$access_row->entity_id,
				EnrollmentSource::GROUP,
				$member->group_id
			);
		}
	}

	/**
	 * Member left a group → expire every enrollment sourced from that group.
	 *
	 * Leaves `status` alone and only sets `expires_at`. Non-group
	 * enrollments (manual, purchase, gift, grant) and enrollments sourced
	 * from a DIFFERENT group are skipped — the user's other acquisition
	 * paths must survive.
	 */
	public function on_member_removed( int $group_id, int $user_id ): void {
		$now = gmdate( 'Y-m-d H:i:s' );

		foreach ( $this->access->list_active_for_group( $group_id ) as $access_row ) {
			if ( AccessEntityType::COURSE !== $access_row->entity_type ) {
				continue;
			}

			$enrollment = $this->enrollments->find_for_user_and_course( $user_id, $access_row->entity_id );
			if ( null === $enrollment ) {
				continue;
			}
			if ( EnrollmentSource::GROUP !== $enrollment->source ) {
				continue;
			}
			if ( $enrollment->source_group_id !== $group_id ) {
				continue;
			}

			$this->enrollments->update( $enrollment->id, [ 'expires_at' => $now ] );
		}
	}

	/**
	 * Group lost access to an entity → expire all its members' enrollments for that entity.
	 *
	 * Identical to {@see self::on_member_removed()} but pivoting on the
	 * other axis — we iterate members for the single entity, instead of
	 * entities for the single member.
	 */
	public function on_access_revoked( int $group_id, AccessEntityType $entity_type, int $entity_id ): void {
		if ( AccessEntityType::COURSE !== $entity_type ) {
			return;
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		foreach ( $this->members->list_active_members( $group_id ) as $member ) {
			$enrollment = $this->enrollments->find_for_user_and_course( $member->user_id, $entity_id );
			if ( null === $enrollment ) {
				continue;
			}
			if ( EnrollmentSource::GROUP !== $enrollment->source ) {
				continue;
			}
			if ( $enrollment->source_group_id !== $group_id ) {
				continue;
			}

			$this->enrollments->update( $enrollment->id, [ 'expires_at' => $now ] );
		}
	}
}
