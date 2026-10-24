<?php
/** Read-only catalogue reconciliation plan. Run with an authorised wp eval-file. */
use Delnavazan\Platform\Core\Application\CatalogueReconciliationService;
if (!defined('WP_CLI') || !WP_CLI) throw new RuntimeException('WP-CLI required');
if (!current_user_can('dzn_manage_courses')) throw new RuntimeException('Catalogue management capability required');
WP_CLI::log(wp_json_encode((new CatalogueReconciliationService())->plan(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
