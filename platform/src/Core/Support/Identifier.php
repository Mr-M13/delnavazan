<?php
namespace Delnavazan\Platform\Core\Support;
final class Identifier {
	private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
	public static function uid(): string {
		$time = (int) floor( microtime( true ) * 1000 ); $out = '';
		for ( $i = 9; $i >= 0; --$i ) { $out .= self::ALPHABET[ ( $time >> ( $i * 5 ) ) & 31 ]; }
		for ( $i = 0; $i < 16; ++$i ) { $out .= self::ALPHABET[ random_int( 0, 31 ) ]; }
		return $out;
	}
	public static function reference( string $prefix, int $id ): string { return $prefix . str_pad( (string) $id, 6, '0', STR_PAD_LEFT ); }
}
