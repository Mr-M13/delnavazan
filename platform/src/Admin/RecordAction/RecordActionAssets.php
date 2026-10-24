<?php
namespace Delnavazan\Platform\Admin\RecordAction;

/** Shared styles for focused record actions. Logical properties keep RTL correct. */
final class RecordActionAssets {
    public static function styles(): void {
        echo '<style>.dzn-record-action-trigger{margin-inline-end:6px}.dzn-record-action-dialog{max-inline-size:min(560px,calc(100vw - 32px));border:1px solid #c3c4c7;border-radius:6px;padding:0;text-align:start}.dzn-record-action-dialog::backdrop{background:rgba(0,0,0,.55)}.dzn-record-action-dialog__header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:16px 18px;border-block-end:1px solid #dcdcde}.dzn-record-action-dialog__header h2{margin:0;font-size:18px;line-height:1.35}.dzn-record-action-dialog__close{font-size:22px;line-height:1;text-decoration:none}.dzn-record-action-dialog__body{padding:16px 18px}.dzn-record-action-dialog__body p{margin-block-start:0}.dzn-record-action-dialog__footer{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:8px;padding:14px 18px;border-block-start:1px solid #dcdcde;background:#f6f7f7}.dzn-record-action-consequence{margin-block:12px;padding:12px;border-inline-start:4px solid #dba617;background:#fcf9e8}.dzn-record-action-confirmation label{display:flex;align-items:flex-start;gap:8px}.dzn-record-action-dialog textarea,.dzn-record-action-dialog input[type="text"],.dzn-record-action-dialog select{inline-size:100%;max-inline-size:100%}@media(max-width:782px){.dzn-record-action-dialog__header,.dzn-record-action-dialog__body,.dzn-record-action-dialog__footer{padding-inline:14px}.dzn-record-action-dialog__footer .button{inline-size:100%}}</style>';
    }
}
