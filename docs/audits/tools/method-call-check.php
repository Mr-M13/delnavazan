<?php
/**
 * Read-only call-site / definition checker.
 *
 * Defect class: a call site names a method the receiver does not declare. PHP only
 * raises it at runtime ("Call to undefined method …"), the operator sees an opaque
 * reference, and a contract test that greps for the call-site spelling keeps CI green.
 *
 * Real instance (2026-11-22, PR #773):
 *   AdministratorIntroBookingService::create() did
 *       $requests = new BookingRequestRepository();
 *       $requests->studentHasActiveInstrumentEnrollment( … )   // "Enrollment"
 *   while the repository declares
 *       studentHasActiveInstrumentEnrolment( … )               // "Enrolment"
 *   → every "Create intro" failed with EWI-… .
 *
 * What it resolves (deliberately conservative, so silence is trustworthy):
 *   • `$this->name()`            → methods of the enclosing class + traits + parent
 *   • `$this->prop->name()`      → type of a typed or annotated property
 *   • `$var->name()`             → type from `$var = new Type(`, a typed parameter,
 *                                  a `@var Type $var` annotation, or a foreach `@var`
 *   • `Type::name()`             → static methods of Type
 *
 * It reports ONLY when the receiver's class is known in the scanned tree and that
 * class declares no such method. Vendor, global and dynamically-proxied receivers
 * are skipped, so silence means "no drift found", not "proved correct everywhere".
 *
 * Usage:
 *   php docs/audits/tools/method-call-check.php [--root=platform/src] [--verbose] [--ignore=a,b]
 *
 * Exit 1 when a call names an undeclared method on a known class, else 0.
 */

$root    = 'platform/src';
$verbose = false;
$ignore  = [];
foreach ( array_slice( $argv, 1 ) as $arg ) {
    if ( str_starts_with( $arg, '--root=' ) ) {
        $root = substr( $arg, 7 );
    } elseif ( $arg === '--verbose' ) {
        $verbose = true;
    } elseif ( str_starts_with( $arg, '--ignore=' ) ) {
        foreach ( explode( ',', substr( $arg, 9 ) ) as $n ) {
            $ignore[ strtolower( trim( $n ) ) ] = true;
        }
    } else {
        fwrite( STDERR, "unknown argument: {$arg}\n" );
        exit( 2 );
    }
}
$root = rtrim( $root, '/' );
if ( ! is_dir( $root ) ) {
    fwrite( STDERR, "root not found: {$root}\n" );
    exit( 2 );
}

/* ------------------------------------------------------------------------- */
/* Helpers                                                                   */
/* ------------------------------------------------------------------------- */

function php_files( string $dir ): array {
    $out = [];
    $it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
    foreach ( $it as $file ) {
        if ( $file->isFile() && strtolower( $file->getExtension() ) === 'php' ) {
            $out[] = $file->getPathname();
        }
    }
    sort( $out );
    return $out;
}

function shortname( string $fqcn ): string {
    $pos = strrpos( $fqcn, '\\' );
    return $pos === false ? $fqcn : substr( $fqcn, $pos + 1 );
}

/**
 * Tokenise a file into [ typeOrChar, text, byteOffset, line ] and pair braces by
 * token index. Token-level pairing is immune to braces inside string literals,
 * which a byte scan silently corrupts.
 */
function tokenise( string $code ): array {
    $raw     = token_get_all( $code );
    $tokens  = [];
    $offset  = 0;
    $line    = 1;
    foreach ( $raw as $t ) {
        $type = is_array( $t ) ? $t[0] : $t;
        $text = is_array( $t ) ? $t[1] : $t;
        $tokens[] = [ 'type' => $type, 'text' => $text, 'offset' => $offset, 'line' => $line ];
        $offset += strlen( $text );
        $line   += substr_count( $text, "\n" );
    }

    $pairs = [];
    $stack = [];
    $count = count( $tokens );
    for ( $i = 0; $i < $count; $i++ ) {
        $type = $tokens[ $i ]['type'];
        $text = $tokens[ $i ]['text'];

        // String interpolation opens a brace with an int-typed token but closes it
        // with a plain '}' character token. Counting only literal '{' goes negative.
        $opens_brace = ( $type === T_CURLY_OPEN || $type === T_DOLLAR_OPEN_CURLY_BRACES );
        $is_char     = is_string( $type );

        if ( $opens_brace || ( $is_char && ( $text === '{' || $text === '(' ) ) ) {
            $stack[] = $i;
        } elseif ( $is_char && ( $text === '}' || $text === ')' ) ) {
            $want = $text === '}' ? '{' : '(';
            for ( $k = count( $stack ) - 1; $k >= 0; $k-- ) {
                $o = $stack[ $k ];
                $o_is = is_string( $tokens[ $o ]['type'] ) ? $tokens[ $o ]['text'] : '{';
                if ( $o_is === $want ) {
                    $pairs[ $stack[ $k ] ] = $i;
                    array_splice( $stack, $k );
                    break;
                }
            }
        }
    }
    return [ $tokens, $pairs ];
}

/** Text between token index $from and $to (exclusive), by byte offsets. */
function span_text( string $code, array $tokens, int $from, int $to ): string {
    $start = $tokens[ $from ]['offset'];
    $end   = $tokens[ $to ]['offset'];
    return substr( $code, $start, $end - $start );
}

/* ------------------------------------------------------------------------- */
/* Pass 1: index classes, methods, properties, parameters, use statements.   */
/* ------------------------------------------------------------------------- */

$files   = php_files( $root );
$classes = [];   // FQCN => record
$short   = [];   // short lower => list of FQCN

foreach ( $files as $file ) {
    $code = file_get_contents( $file );
    [ $tokens, $pairs ] = tokenise( $code );
    $n = count( $tokens );

    // namespace + use statements
    $namespace = '';
    $uses      = [];
    for ( $i = 0; $i < $n; $i++ ) {
        $t = $tokens[ $i ];
        if ( $t['type'] === T_NAMESPACE ) {
            $namespace = '';
            for ( $j = $i + 1; $j < $n; $j++ ) {
                $u = $tokens[ $j ];
                if ( in_array( $u['type'], [ T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR ], true ) ) {
                    $namespace .= $u['text'];
                } elseif ( in_array( $u['type'], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
                    continue;
                } else {
                    break;
                }
            }
        }
        if ( $t['type'] === T_USE ) {
            $prev = $i - 1;
            while ( $prev >= 0 && $tokens[ $prev ]['type'] === T_WHITESPACE ) {
                $prev--;
            }
            if ( $prev >= 0 && in_array( $tokens[ $prev ]['type'], [ T_FUNCTION, T_FN ], true ) ) {
                continue; // closure use
            }
            $buf = '';
            for ( $j = $i + 1; $j < $n; $j++ ) {
                $u = $tokens[ $j ];
                if ( $u['text'] === ';' ) {
                    break;
                }
                if ( $u['type'] === T_FUNCTION ) {
                    break;
                }
                $buf .= $u['text'];
            }
            foreach ( explode( ',', $buf ) as $import ) {
                $import = trim( $import );
                if ( $import === '' ) {
                    continue;
                }
                $parts = preg_split( '/\s+as\s+/i', $import );
                $fq    = trim( $parts[0] );
                $alias = isset( $parts[1] ) ? trim( $parts[1] ) : shortname( $fq );
                $uses[ strtolower( $alias ) ] = ltrim( $fq, '\\' );
            }
        }
    }

    // class / trait / interface declarations with a body
    for ( $i = 0; $i < $n; $i++ ) {
        $t = $tokens[ $i ];
        if ( ! in_array( $t['type'], [ T_CLASS, T_TRAIT, T_INTERFACE ], true ) ) {
            continue;
        }
        $prev = $i - 1;
        while ( $prev >= 0 && $tokens[ $prev ]['type'] === T_WHITESPACE ) {
            $prev--;
        }
        if ( $prev >= 0 && $tokens[ $prev ]['type'] === T_DOUBLE_COLON ) {
            continue; // Foo::class
        }

        $name  = '';
        $brace = null;
        for ( $j = $i + 1; $j < $n; $j++ ) {
            $u = $tokens[ $j ];
            if ( $u['type'] === T_STRING && $name === '' ) {
                $name = $u['text'];
            }
            if ( is_string( $u['type'] ) && $u['text'] === '{' ) {
                $brace = $j;
                break;
            }
        }
        if ( $name === '' || $brace === null || ! isset( $pairs[ $brace ] ) ) {
            continue; // anonymous class
        }
        $close = $pairs[ $brace ];
        $body  = span_text( $code, $tokens, $brace + 1, $close );

        $header = '';
        for ( $j = $i; $j <= $brace; $j++ ) {
            $header .= $tokens[ $j ]['text'];
        }

        $fqcn = ( $namespace !== '' ? $namespace . '\\' : '' ) . $name;

        // Trait use and method declarations, token-based so string braces cannot lie.
        $traits  = [];
        $methods = [];
        $depth   = 0;   // function nesting inside the class body
        for ( $j = $brace + 1; $j < $close; $j++ ) {
            $u = $tokens[ $j ];
            if ( $u['type'] === T_CURLY_OPEN || $u['type'] === T_DOLLAR_OPEN_CURLY_BRACES ) {
                $depth++;
                continue;
            }
            if ( is_string( $u['type'] ) ) {
                if ( $u['text'] === '{' ) {
                    $depth++;
                } elseif ( $u['text'] === '}' ) {
                    $depth--;
                }
                continue;
            }
            if ( $u['type'] === T_FUNCTION && $depth === 0 ) {
                // Method name, skipping `&` and whitespace.
                $k = $j + 1;
                while ( $k < $close && in_array( $tokens[ $k ]['type'], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
                    $k++;
                }
                if ( $k < $close && $tokens[ $k ]['text'] === '&' ) {
                    $k++;
                    while ( $k < $close && $tokens[ $k ]['type'] === T_WHITESPACE ) {
                        $k++;
                    }
                }
                $mname = null;
                if ( $k < $close ) {
                    $candidate = $tokens[ $k ]['text'];
                    // Method names may be reserved words (e.g. `function require()`).
                    if ( preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $candidate ) ) {
                        $mname   = $candidate;
                        $nameIdx = $k;
                    }
                }
                // Find the parameter list.
                $openParen = null;
                for ( $p = $k + 1; $p < $close; $p++ ) {
                    if ( is_string( $tokens[ $p ]['type'] ) && $tokens[ $p ]['text'] === '(' ) {
                        $openParen = $p;
                        break;
                    }
                    if ( is_string( $tokens[ $p ]['type'] ) && $tokens[ $p ]['text'] === ';' ) {
                        break;   // abstract/interface method, no body
                    }
                }
                $paramsRaw  = '';
                $methodBody = '';
                if ( $openParen !== null && isset( $pairs[ $openParen ] ) ) {
                    $closeParen = $pairs[ $openParen ];
                    $paramsRaw  = span_text( $code, $tokens, $openParen + 1, $closeParen );
                    $next = $closeParen + 1;
                    while ( $next < $close && in_array( $tokens[ $next ]['type'], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
                        $next++;
                    }
                    // Optional `: Type` return declaration.
                    if ( $next < $close && is_string( $tokens[ $next ]['type'] ) && $tokens[ $next ]['text'] === ':' ) {
                        $next++;
                        while ( $next < $close && $tokens[ $next ]['text'] !== '{' && $tokens[ $next ]['text'] !== ';' ) {
                            $next++;
                        }
                    }
                    if ( $next < $close && is_string( $tokens[ $next ]['type'] ) && $tokens[ $next ]['text'] === '{' && isset( $pairs[ $next ] ) ) {
                        $methodBody = span_text( $code, $tokens, $next + 1, $pairs[ $next ] );
                    }
                }
                if ( $mname !== null && $mname !== '' ) {
                    $methods[ strtolower( $mname ) ] = [
                        'name'   => $mname,
                        'params' => $paramsRaw,
                        'body'   => $methodBody,
                        'line'   => $tokens[ $nameIdx ]['line'],
                    ];
                }
                continue;
            }
            if ( $u['type'] === T_USE && $depth === 0 ) {
                $buf = '';
                for ( $k = $j + 1; $k < $close; $k++ ) {
                    if ( $tokens[ $k ]['text'] === ';' || $tokens[ $k ]['text'] === '{' ) {
                        break;
                    }
                    $buf .= $tokens[ $k ]['text'];
                }
                foreach ( explode( ',', $buf ) as $tr ) {
                    $tr = trim( $tr );
                    if ( $tr !== '' ) {
                        $traits[] = $tr;
                    }
                }
            }
        }

        $parent = null;
        if ( preg_match( '/\bextends\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/', $header, $pm ) ) {
            $parent = $pm[1];
        }

        // Typed properties.
        $props = [];
        if ( preg_match_all(
            '/(?:private|protected|public)\s+(?:static\s+)?(?:readonly\s+)?(\??[A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s+\$([A-Za-z_][A-Za-z0-9_]*)\s*(?:=|;)/',
            $body,
            $pm2,
            PREG_SET_ORDER
        ) ) {
            foreach ( $pm2 as $p ) {
                $props[ $p[2] ] = ltrim( $p[1], '?' );
            }
        }

        $classes[ $fqcn ] = [
            'file'      => $file,
            'body'      => $body,
            'methods'   => $methods,
            'props'     => $props,
            'traits'    => $traits,
            'parent'    => $parent,
            'uses'      => $uses,
            'namespace' => $namespace,
            'kind'      => $t['type'] === T_TRAIT ? 'trait' : ( $t['type'] === T_INTERFACE ? 'interface' : 'class' ),
            'line'      => $t['line'],
        ];
        $short[ strtolower( $name ) ][] = $fqcn;
    }
}

/** Resolve a written class name to a known FQCN, using the use-map and namespace. */
function resolve_class( string $written, array $ctx ): ?string {
    global $classes, $short;
    $written = ltrim( $written, '\\' );
    if ( isset( $classes[ $written ] ) ) {
        return $written;
    }
    $segments = explode( '\\', $written );
    $first    = strtolower( $segments[0] );
    if ( isset( $ctx['uses'][ $first ] ) ) {
        $candidate = $ctx['uses'][ $first ] . ( count( $segments ) > 1 ? '\\' . implode( '\\', array_slice( $segments, 1 ) ) : '' );
        if ( isset( $classes[ $candidate ] ) ) {
            return $candidate;
        }
    }
    $sameNs = ( $ctx['namespace'] !== '' ? $ctx['namespace'] . '\\' : '' ) . $written;
    if ( isset( $classes[ $sameNs ] ) ) {
        return $sameNs;
    }
    $s   = shortname( $written );
    $key = strtolower( $s );
    if ( isset( $short[ $key ] ) && count( $short[ $key ] ) === 1 ) {
        return $short[ $key ][0];
    }
    return null;
}

/** All method names for a class including traits and parent chain. */
function effective_methods( string $fqcn, array &$memo ): array {
    global $classes;
    if ( isset( $memo[ $fqcn ] ) ) {
        return $memo[ $fqcn ];
    }
    $memo[ $fqcn ] = [];   // cycle guard
    if ( ! isset( $classes[ $fqcn ] ) ) {
        return [];
    }
    $rec = $classes[ $fqcn ];
    $out = array_keys( $rec['methods'] );
    $ctx = [ 'uses' => $rec['uses'], 'namespace' => $rec['namespace'] ];
    foreach ( $rec['traits'] as $trait ) {
        $resolved = resolve_class( $trait, $ctx );
        if ( $resolved !== null ) {
            $out = array_merge( $out, effective_methods( $resolved, $memo ) );
        }
    }
    if ( $rec['parent'] !== null ) {
        $resolved = resolve_class( $rec['parent'], $ctx );
        if ( $resolved !== null ) {
            $out = array_merge( $out, effective_methods( $resolved, $memo ) );
        }
    }
    $memo[ $fqcn ] = array_values( array_unique( $out ) );
    return $memo[ $fqcn ];
}

/** All typed properties for a class including traits and parent chain. */
function effective_props( string $fqcn, array &$memo ): array {
    global $classes;
    if ( isset( $memo[ $fqcn ] ) ) {
        return $memo[ $fqcn ];
    }
    $memo[ $fqcn ] = [];   // cycle guard
    if ( ! isset( $classes[ $fqcn ] ) ) {
        return [];
    }
    $rec = $classes[ $fqcn ];
    $out = $rec['props'];
    $ctx = [ 'uses' => $rec['uses'], 'namespace' => $rec['namespace'] ];
    foreach ( $rec['traits'] as $trait ) {
        $resolved = resolve_class( $trait, $ctx );
        if ( $resolved !== null ) {
            $out += effective_props( $resolved, $memo );
        }
    }
    if ( $rec['parent'] !== null ) {
        $resolved = resolve_class( $rec['parent'], $ctx );
        if ( $resolved !== null ) {
            $out += effective_props( $resolved, $memo );
        }
    }
    $memo[ $fqcn ] = $out;
    return $memo[ $fqcn ];
}

/** True when a class inherits from something outside the scanned tree. */
function is_opaque( string $fqcn, array &$memo ): bool {
    global $classes;
    if ( isset( $memo[ $fqcn ] ) ) {
        return $memo[ $fqcn ];
    }
    $memo[ $fqcn ] = false;
    if ( ! isset( $classes[ $fqcn ] ) ) {
        return false;
    }
    $rec = $classes[ $fqcn ];
    $ctx = [ 'uses' => $rec['uses'], 'namespace' => $rec['namespace'] ];
    $opaque = ( $rec['parent'] !== null && resolve_class( $rec['parent'], $ctx ) === null );
    foreach ( $rec['traits'] as $trait ) {
        if ( resolve_class( $trait, $ctx ) === null ) {
            $opaque = true;
        }
    }
    $memo[ $fqcn ] = $opaque;
    return $opaque;
}

$memo      = [];
$propMemo  = [];
$opaqueMemo = [];

/* ------------------------------------------------------------------------- */
/* Pass 2: resolve calls.                                                    */
/* ------------------------------------------------------------------------- */

$findings      = [];
$resolvedCalls = 0;
$skipped       = 0;

foreach ( $classes as $fqcn => $rec ) {
    if ( $rec['kind'] === 'interface' ) {
        continue;
    }
    $ctx     = [ 'uses' => $rec['uses'], 'namespace' => $rec['namespace'] ];
    $hasCall = isset( $rec['methods']['__call'] ) || isset( $rec['methods']['__callstatic'] );
    $allProps = effective_props( $fqcn, $propMemo );

    // If the class inherits from something outside the scanned tree (vendor or core),
    // its callable surface is unknown; claiming a missing method there would be a lie.
    if ( is_opaque( $fqcn, $opaqueMemo ) ) {
        continue;
    }

    foreach ( $rec['methods'] as $method ) {
        $body = $method['body'];
        if ( $body === '' ) {
            continue;
        }

        // Local variable types, ordered by byte offset so a call sees the most
        // recent assignment before it rather than the last one in the method.
        $vars     = [];   // name => list of [ offset, type ]
        $params   = [];

        preg_match_all(
            '/(?:^|,|\()\s*(?:(?:private|protected|public)\s+)?(?:readonly\s+)?(\??[A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s+&?\s*\$([A-Za-z_][A-Za-z0-9_]*)/',
            $method['params'],
            $pm,
            PREG_SET_ORDER
        );
        foreach ( $pm as $p ) {
            $params[ $p[2] ] = ltrim( $p[1], '?' );
        }
        if ( preg_match_all( '/\$([A-Za-z_]\w*)\s*=\s*new\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*\(/', $body, $nm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            foreach ( $nm as $m ) {
                if ( $m[2][0] !== 'self' && $m[2][0] !== 'static' && $m[2][0] !== 'parent' ) {
                    $vars[ $m[1][0] ][] = [ $m[0][1], $m[2][0] ];
                }
            }
        }
        if ( preg_match_all( '/@var\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\|]*)\s+\$([A-Za-z_]\w*)/', $body, $vm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            foreach ( $vm as $m ) {
                $vars[ $m[2][0] ][] = [ $m[0][1], explode( '|', $m[1][0] )[0] ];
            }
        }
        // instanceof narrows the receiver for the rest of the expression:
        //   $repository instanceof TeacherRepository && $repository->hasOperationalEnrolments()
        if ( preg_match_all( '/\$([A-Za-z_]\w*)\s+instanceof\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/', $body, $im, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            foreach ( $im as $m ) {
                $vars[ $m[1][0] ][] = [ $m[0][1], $m[2][0] ];
            }
        }
        // The same for a property: $this->recipients instanceof BoundPort && …
        $prop_narrow = [];
        if ( preg_match_all( '/\$this->([A-Za-z_]\w*)\s+instanceof\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/', $body, $pn, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            foreach ( $pn as $m ) {
                $prop_narrow[ $m[1][0] ][] = [ $m[0][1], $m[2][0] ];
            }
        }
        foreach ( $prop_narrow as $name => $list ) {
            usort( $list, fn( $a, $b ) => $a[0] <=> $b[0] );
            $prop_narrow[ $name ] = $list;
        }
        foreach ( $vars as $name => $list ) {
            usort( $list, fn( $a, $b ) => $a[0] <=> $b[0] );
            $vars[ $name ] = $list;
        }

        /** Type of $name as seen at byte $at within the method body. */
        $type_at = function ( string $name, int $at ) use ( $vars, $params ): ?string {
            if ( isset( $vars[ $name ] ) ) {
                $found = null;
                foreach ( $vars[ $name ] as [ $offset, $type ] ) {
                    if ( $offset < $at ) {
                        $found = $type;
                    } else {
                        break;
                    }
                }
                if ( $found !== null ) {
                    return $found;
                }
            }
            return $params[ $name ] ?? null;
        };

        if ( ! preg_match_all(
            '/(\$this->[A-Za-z_]\w*|\$[A-Za-z_]\w*|[A-Za-z_\\\\][A-Za-z0-9_\\\\]*)->\s*([A-Za-z_]\w*)\s*\(|([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::\s*([A-Za-z_]\w*)\s*\(/',
            $body,
            $cm,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        ) ) {
            continue;
        }

        foreach ( $cm as $c ) {
            $isStatic = false;
            if ( isset( $c[1] ) && $c[1][1] >= 0 && $c[1][0] !== '' ) {
                $receiver = $c[1][0];
                $called   = $c[2][0];
            } else {
                $receiver = $c[3][0];
                $called   = $c[4][0];
                $isStatic = true;
            }

            $line = $method['line'] + substr_count( substr( $body, 0, $c[0][1] ), "\n" );
            $targetFqcn = null;

            if ( $isStatic ) {
                $lower = strtolower( $receiver );
                if ( $lower === 'self' || $lower === 'static' ) {
                    $targetFqcn = $fqcn;
                } elseif ( $lower === 'parent' ) {
                    $targetFqcn = $rec['parent'] !== null ? resolve_class( $rec['parent'], $ctx ) : null;
                } else {
                    $targetFqcn = resolve_class( $receiver, $ctx );
                }
            } elseif ( $receiver === '$this' ) {
                $targetFqcn = $fqcn;
            } elseif ( str_starts_with( $receiver, '$this->' ) ) {
                $prop = substr( $receiver, 7 );
                $at   = $c[0][1];
                if ( isset( $prop_narrow[ $prop ] ) ) {
                    $narrowed = null;
                    foreach ( $prop_narrow[ $prop ] as [ $offset, $type ] ) {
                        if ( $offset < $at ) {
                            $narrowed = $type;
                        } else {
                            break;
                        }
                    }
                    if ( $narrowed !== null ) {
                        $targetFqcn = resolve_class( $narrowed, $ctx );
                    }
                }
                if ( $targetFqcn === null && isset( $allProps[ $prop ] ) ) {
                    $targetFqcn = resolve_class( $allProps[ $prop ], $ctx );
                }
            } else {
                $var = substr( $receiver, 1 );
                $type = $type_at( $var, $c[0][1] );
                if ( $type !== null ) {
                    $targetFqcn = resolve_class( $type, $ctx );
                }
            }

            if ( $targetFqcn === null || ! isset( $classes[ $targetFqcn ] ) ) {
                $skipped++;
                continue;
            }
            if ( is_opaque( $targetFqcn, $opaqueMemo ) ) {
                $skipped++;
                continue;
            }
            $resolvedCalls++;

            if ( $hasCall || isset( $ignore[ strtolower( $called ) ] ) ) {
                continue;
            }

            // Explicit runtime guard: method_exists( …, 'name' ) means the author knows the
            // port surface is wider than its declaration, and handles the narrow interface.
            if ( str_contains( $body, 'method_exists' )
                && preg_match( "/(['\"])" . preg_quote( $called, '/' ) . "\\1/", $body ) ) {
                continue;
            }

            $known = effective_methods( $targetFqcn, $memo );
            if ( in_array( strtolower( $called ), $known, true ) ) {
                continue;
            }

            $findings[] = [
                'file'      => $rec['file'],
                'line'      => $line,
                'called'    => $called,
                'target'    => $targetFqcn,
                'receiver'  => $receiver,
                'static'    => $isStatic,
                'enclosing' => $fqcn,
            ];
        }
    }
}

if ( $verbose ) {
    echo 'Scanned ' . count( $files ) . ' files, ' . count( $classes ) . " classes/traits.\n";
    echo "Resolved {$resolvedCalls} typed call sites; skipped {$skipped} whose receiver type is not in the tree.\n\n";
}

if ( ! $findings ) {
    echo "OK: every typed call site names a method its receiver declares ({$resolvedCalls} resolved).\n";
    exit( 0 );
}

echo 'UNDEFINED METHOD CALLS: ' . count( $findings ) . "\n\n";
$seen = [];
foreach ( $findings as $f ) {
    $key = $f['file'] . ':' . $f['line'] . ':' . $f['called'];
    if ( isset( $seen[ $key ] ) ) {
        continue;
    }
    $seen[ $key ] = true;
    echo $f['file'] . ':' . $f['line'] . "\n";
    echo '    ' . $f['receiver'] . ( $f['static'] ? '::' : '->' ) . $f['called'] . "()\n";
    echo '    receiver type: ' . $f['target'] . " — declares no such method\n";
    echo '    in ' . $f['enclosing'] . "\n\n";
}
echo "Each of these is a runtime fatal on the path that reaches it. Pair every fix with a\n";
echo "contract test that parses the call against the declaration, not a string grep.\n";
exit( 1 );
