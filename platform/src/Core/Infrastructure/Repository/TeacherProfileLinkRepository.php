<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;
final class TeacherProfileLinkRepository extends BaseRepository { public function __construct(){ global $wpdb; $this->table=$wpdb->prefix . 'dzn_teacher_profile_links'; } }
