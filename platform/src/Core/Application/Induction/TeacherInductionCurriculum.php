<?php
namespace Delnavazan\Platform\Core\Application\Induction;

/**
 * Versioned, code-owned teacher induction curriculum.
 *
 * Content lives in platform/content/induction/*.php. This loader is the only place that
 * enumerates modules, so rendering never defines educational content and a module cannot be
 * invented by the Theme. Bump a module's version for a material policy change; bump
 * acknowledgement_version only when previously completed teachers must explicitly re-acknowledge.
 */
final class TeacherInductionCurriculum {
    /** Canonical learning order. */
    private const ORDER = array(
        'portal',
        'availability',
        'responsibilities',
        'schedule-changes',
        'absence-replacement',
        'finance',
        'escalation',
    );

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $modules = null;

    /** @return array<int, array<string, mixed>> */
    public static function all(): array {
        if ( null !== self::$modules ) {
            return array_values( self::$modules );
        }
        $loaded = array();
        $directory = dirname( __DIR__, 4 ) . '/content/induction';
        foreach ( self::ORDER as $index => $id ) {
            $file = $directory . '/' . $id . '.php';
            if ( ! is_readable( $file ) ) {
                throw new \RuntimeException( 'Induction module content is missing: ' . $id );
            }
            $module = require $file;
            if ( ! is_array( $module ) ) {
                throw new \RuntimeException( 'Induction module content is invalid: ' . $id );
            }
            self::validate( $module, $id, $index + 1 );
            $module['order'] = $index + 1;
            $loaded[ $id ] = $module;
        }
        self::$modules = $loaded;
        return array_values( $loaded );
    }

    /** @return array<string, mixed>|null */
    public static function module( string $id ): ?array {
        $id = self::moduleId( $id );
        if ( '' === $id ) {
            return null;
        }
        foreach ( self::all() as $module ) {
            if ( $module['id'] === $id ) {
                return $module;
            }
        }
        return null;
    }

    /** @return array<int, string> */
    public static function ids(): array {
        return self::ORDER;
    }

    public static function total(): int {
        return count( self::ORDER );
    }

    /** Only a canonical module id may ever reach persistence. */
    public static function moduleId( string $value ): string {
        $value = strtolower( trim( $value ) );
        return in_array( $value, self::ORDER, true ) ? $value : '';
    }

    private static function validate( array $module, string $id, int $order ): void {
        foreach ( array( 'id', 'title', 'short_title', 'description', 'portal_screen', 'completion_criteria' ) as $field ) {
            if ( ! isset( $module[ $field ] ) || ! is_string( $module[ $field ] ) || '' === trim( $module[ $field ] ) ) {
                throw new \RuntimeException( 'Induction module ' . $id . ' is missing ' . $field );
            }
        }
        if ( $module['id'] !== $id ) {
            throw new \RuntimeException( 'Induction module id mismatch: ' . $id );
        }
        if ( ! isset( $module['version'] ) || (int) $module['version'] < 1 ) {
            throw new \RuntimeException( 'Induction module ' . $id . ' must declare a positive content version' );
        }
        foreach ( array( 'objectives', 'sections' ) as $list ) {
            if ( ! isset( $module[ $list ] ) || ! is_array( $module[ $list ] ) || ! $module[ $list ] ) {
                throw new \RuntimeException( 'Induction module ' . $id . ' is missing ' . $list );
            }
        }
        foreach ( $module['sections'] as $section ) {
            if ( ! is_array( $section ) || ! isset( $section['id'], $section['title'], $section['body'] ) ) {
                throw new \RuntimeException( 'Induction module ' . $id . ' has an invalid section' );
            }
        }
    }
}
