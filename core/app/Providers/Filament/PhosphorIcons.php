<?php

namespace App\Providers\Filament;

use Filament\Actions\View\ActionsIconAlias;
use Filament\Forms\View\FormsIconAlias;
use Filament\Infolists\View\InfolistsIconAlias;
use Filament\Notifications\View\NotificationsIconAlias;
use Filament\Schemas\View\SchemaIconAlias;
use Filament\Support\Facades\FilamentIcon;
use Filament\Support\View\SupportIconAlias;
use Filament\Tables\View\TablesIconAlias;
use Filament\View\PanelsIconAlias;
use Filament\Widgets\View\WidgetsIconAlias;

/** Filament's own UI icons rendered from Phosphor (regular weight), the icon set of the Kumo design language. */
class PhosphorIcons
{
    public static function register(): void
    {
        FilamentIcon::register(array_map(fn (string $name): string => 'phosphor-'.$name, self::aliases()));
    }

    /** @return array<string, string> Filament icon alias => Phosphor icon name */
    public static function aliases(): array
    {
        return [
            PanelsIconAlias::GLOBAL_SEARCH_FIELD => 'magnifying-glass',
            PanelsIconAlias::PAGES_DASHBOARD_ACTIONS_FILTER => 'funnel',
            PanelsIconAlias::PAGES_DASHBOARD_NAVIGATION_ITEM => 'house',
            PanelsIconAlias::PAGES_PASSWORD_RESET_REQUEST_PASSWORD_RESET_ACTIONS_LOGIN => 'arrow-left',
            PanelsIconAlias::PAGES_PASSWORD_RESET_REQUEST_PASSWORD_RESET_ACTIONS_LOGIN_RTL => 'arrow-right',
            PanelsIconAlias::RESOURCES_PAGES_EDIT_RECORD_NAVIGATION_ITEM => 'pencil-simple',
            PanelsIconAlias::RESOURCES_PAGES_MANAGE_RELATED_RECORDS_NAVIGATION_ITEM => 'stack',
            PanelsIconAlias::RESOURCES_PAGES_VIEW_RECORD_NAVIGATION_ITEM => 'eye',
            PanelsIconAlias::SIDEBAR_COLLAPSE_BUTTON => 'sidebar-simple',
            PanelsIconAlias::SIDEBAR_COLLAPSE_BUTTON_RTL => 'sidebar-simple',
            PanelsIconAlias::SIDEBAR_EXPAND_BUTTON => 'sidebar-simple',
            PanelsIconAlias::SIDEBAR_EXPAND_BUTTON_RTL => 'sidebar-simple',
            PanelsIconAlias::SIDEBAR_GROUP_COLLAPSE_BUTTON => 'caret-up',
            PanelsIconAlias::SIDEBAR_OPEN_DATABASE_NOTIFICATIONS_BUTTON => 'bell',
            PanelsIconAlias::SUB_NAVIGATION_MOBILE_MENU_BUTTON => 'caret-down',
            PanelsIconAlias::THEME_SWITCHER_LIGHT_BUTTON => 'sun',
            PanelsIconAlias::THEME_SWITCHER_DARK_BUTTON => 'moon',
            PanelsIconAlias::THEME_SWITCHER_SYSTEM_BUTTON => 'desktop',
            PanelsIconAlias::TOPBAR_CLOSE_SIDEBAR_BUTTON => 'x',
            PanelsIconAlias::TOPBAR_GROUP_TOGGLE_BUTTON => 'caret-down',
            PanelsIconAlias::TOPBAR_OPEN_DATABASE_NOTIFICATIONS_BUTTON => 'bell',
            PanelsIconAlias::TOPBAR_OPEN_SIDEBAR_BUTTON => 'list',
            PanelsIconAlias::USER_MENU_LOGOUT_BUTTON => 'sign-out',
            PanelsIconAlias::USER_MENU_PROFILE_ITEM => 'user-circle',
            PanelsIconAlias::USER_MENU_TOGGLE_BUTTON => 'caret-up-down',
            PanelsIconAlias::WIDGETS_ACCOUNT_LOGOUT_BUTTON => 'sign-out',
            PanelsIconAlias::AUTH_MULTI_FACTOR_APP_ACTIONS_DISABLE => 'lock-open',
            PanelsIconAlias::AUTH_MULTI_FACTOR_APP_ACTIONS_DISABLE_MODAL => 'lock-open',
            PanelsIconAlias::AUTH_MULTI_FACTOR_APP_ACTIONS_DISABLE_NOTIFICATION => 'lock-open',
            PanelsIconAlias::AUTH_MULTI_FACTOR_APP_ACTIONS_REGENERATE_RECOVERY_CODES => 'arrows-clockwise',
            PanelsIconAlias::AUTH_MULTI_FACTOR_APP_ACTIONS_REGENERATE_RECOVERY_CODES_MODAL => 'arrows-clockwise',
            PanelsIconAlias::AUTH_MULTI_FACTOR_APP_ACTIONS_REGENERATE_RECOVERY_CODES_NOTIFICATION => 'arrows-clockwise',
            PanelsIconAlias::AUTH_MULTI_FACTOR_APP_ACTIONS_SET_UP => 'lock',
            PanelsIconAlias::AUTH_MULTI_FACTOR_APP_ACTIONS_SET_UP_MODAL => 'lock',
            PanelsIconAlias::AUTH_MULTI_FACTOR_APP_ACTIONS_SET_UP_NOTIFICATION => 'lock',

            SupportIconAlias::BREADCRUMBS_SEPARATOR => 'caret-right',
            SupportIconAlias::BREADCRUMBS_SEPARATOR_RTL => 'caret-left',
            SupportIconAlias::MODAL_CLOSE_BUTTON => 'x',
            SupportIconAlias::PAGINATION_FIRST_BUTTON => 'caret-double-left',
            SupportIconAlias::PAGINATION_FIRST_BUTTON_RTL => 'caret-double-right',
            SupportIconAlias::PAGINATION_LAST_BUTTON => 'caret-double-right',
            SupportIconAlias::PAGINATION_LAST_BUTTON_RTL => 'caret-double-left',
            SupportIconAlias::PAGINATION_NEXT_BUTTON => 'caret-right',
            SupportIconAlias::PAGINATION_NEXT_BUTTON_RTL => 'caret-left',
            SupportIconAlias::PAGINATION_PREVIOUS_BUTTON => 'caret-left',
            SupportIconAlias::PAGINATION_PREVIOUS_BUTTON_RTL => 'caret-right',
            SupportIconAlias::SECTION_COLLAPSE_BUTTON => 'caret-up',

            ActionsIconAlias::ACTION_GROUP => 'dots-three',
            ActionsIconAlias::CREATE_ACTION_GROUPED => 'plus',
            ActionsIconAlias::DELETE_ACTION => 'trash',
            ActionsIconAlias::DELETE_ACTION_GROUPED => 'trash',
            ActionsIconAlias::DELETE_ACTION_MODAL => 'trash',
            ActionsIconAlias::DETACH_ACTION => 'x',
            ActionsIconAlias::DETACH_ACTION_MODAL => 'x',
            ActionsIconAlias::DISSOCIATE_ACTION => 'x',
            ActionsIconAlias::DISSOCIATE_ACTION_MODAL => 'x',
            ActionsIconAlias::EDIT_ACTION => 'pencil-simple',
            ActionsIconAlias::EDIT_ACTION_GROUPED => 'pencil-simple',
            ActionsIconAlias::EXPORT_ACTION_GROUPED => 'download-simple',
            ActionsIconAlias::FORCE_DELETE_ACTION => 'trash',
            ActionsIconAlias::FORCE_DELETE_ACTION_GROUPED => 'trash',
            ActionsIconAlias::FORCE_DELETE_ACTION_MODAL => 'trash',
            ActionsIconAlias::IMPORT_ACTION_GROUPED => 'upload-simple',
            ActionsIconAlias::MODAL_CONFIRMATION => 'warning',
            ActionsIconAlias::REPLICATE_ACTION => 'copy',
            ActionsIconAlias::REPLICATE_ACTION_GROUPED => 'copy',
            ActionsIconAlias::RESTORE_ACTION => 'arrow-counter-clockwise',
            ActionsIconAlias::RESTORE_ACTION_GROUPED => 'arrow-counter-clockwise',
            ActionsIconAlias::RESTORE_ACTION_MODAL => 'arrow-counter-clockwise',
            ActionsIconAlias::VIEW_ACTION => 'eye',
            ActionsIconAlias::VIEW_ACTION_GROUPED => 'eye',

            TablesIconAlias::ACTIONS_COLUMN_MANAGER => 'columns',
            TablesIconAlias::ACTIONS_DISABLE_REORDERING => 'check',
            TablesIconAlias::ACTIONS_ENABLE_REORDERING => 'arrows-down-up',
            TablesIconAlias::ACTIONS_FILTER => 'funnel',
            TablesIconAlias::ACTIONS_GROUP => 'stack',
            TablesIconAlias::ACTIONS_OPEN_BULK_ACTIONS => 'dots-three',
            TablesIconAlias::COLUMNS_COLLAPSE_BUTTON => 'caret-down',
            TablesIconAlias::COLUMNS_ICON_COLUMN_FALSE => 'x-circle',
            TablesIconAlias::COLUMNS_ICON_COLUMN_TRUE => 'check-circle',
            TablesIconAlias::EMPTY_STATE => 'tray',
            TablesIconAlias::FILTERS_REMOVE_ALL_BUTTON => 'x',
            TablesIconAlias::GROUPING_COLLAPSE_BUTTON => 'caret-up',
            TablesIconAlias::HEADER_CELL_SORT_ASC_BUTTON => 'caret-up',
            TablesIconAlias::HEADER_CELL_SORT_DESC_BUTTON => 'caret-down',
            TablesIconAlias::HEADER_CELL_SORT_BUTTON => 'caret-up-down',
            TablesIconAlias::REORDER_HANDLE => 'dots-six-vertical',
            TablesIconAlias::SEARCH_FIELD => 'magnifying-glass',

            FormsIconAlias::COMPONENTS_CHECKBOX_LIST_SEARCH_FIELD => 'magnifying-glass',
            FormsIconAlias::COMPONENTS_KEY_VALUE_ACTIONS_DELETE => 'trash',
            FormsIconAlias::COMPONENTS_KEY_VALUE_ACTIONS_REORDER => 'dots-six-vertical',
            FormsIconAlias::COMPONENTS_REPEATER_ACTIONS_CLONE => 'copy',
            FormsIconAlias::COMPONENTS_REPEATER_ACTIONS_COLLAPSE => 'caret-up',
            FormsIconAlias::COMPONENTS_REPEATER_ACTIONS_DELETE => 'trash',
            FormsIconAlias::COMPONENTS_REPEATER_ACTIONS_EXPAND => 'caret-down',
            FormsIconAlias::COMPONENTS_REPEATER_ACTIONS_MOVE_DOWN => 'arrow-down',
            FormsIconAlias::COMPONENTS_REPEATER_ACTIONS_MOVE_UP => 'arrow-up',
            FormsIconAlias::COMPONENTS_REPEATER_ACTIONS_REORDER => 'dots-six-vertical',
            FormsIconAlias::COMPONENTS_SELECT_ACTIONS_CREATE_OPTION => 'plus',
            FormsIconAlias::COMPONENTS_SELECT_ACTIONS_EDIT_OPTION => 'pencil-simple',
            FormsIconAlias::COMPONENTS_TEXT_INPUT_ACTIONS_COPY => 'copy',
            FormsIconAlias::COMPONENTS_TEXT_INPUT_ACTIONS_HIDE_PASSWORD => 'eye-slash',
            FormsIconAlias::COMPONENTS_TEXT_INPUT_ACTIONS_SHOW_PASSWORD => 'eye',
            FormsIconAlias::COMPONENTS_TOGGLE_BUTTONS_BOOLEAN_FALSE => 'x',
            FormsIconAlias::COMPONENTS_TOGGLE_BUTTONS_BOOLEAN_TRUE => 'check',

            InfolistsIconAlias::COMPONENTS_ICON_ENTRY_FALSE => 'x-circle',
            InfolistsIconAlias::COMPONENTS_ICON_ENTRY_TRUE => 'check-circle',

            NotificationsIconAlias::DATABASE_MODAL_EMPTY_STATE => 'bell-slash',
            NotificationsIconAlias::NOTIFICATION_CLOSE_BUTTON => 'x',
            NotificationsIconAlias::NOTIFICATION_DANGER => 'x-circle',
            NotificationsIconAlias::NOTIFICATION_INFO => 'info',
            NotificationsIconAlias::NOTIFICATION_SUCCESS => 'check-circle',
            NotificationsIconAlias::NOTIFICATION_WARNING => 'warning-circle',

            SchemaIconAlias::COMPONENTS_CALLOUT_DANGER => 'x-circle',
            SchemaIconAlias::COMPONENTS_CALLOUT_INFO => 'info',
            SchemaIconAlias::COMPONENTS_CALLOUT_SUCCESS => 'check-circle',
            SchemaIconAlias::COMPONENTS_CALLOUT_WARNING => 'warning-circle',
            SchemaIconAlias::COMPONENTS_TABS_DROPDOWN_TRIGGER_BUTTON => 'caret-down',
            SchemaIconAlias::COMPONENTS_TABS_MORE_TABS_BUTTON => 'dots-three',
            SchemaIconAlias::COMPONENTS_WIZARD_COMPLETED_STEP => 'check',

            WidgetsIconAlias::CHART_WIDGET_EMPTY_STATE => 'chart-line',
            WidgetsIconAlias::CHART_WIDGET_FILTER => 'funnel',
        ];
    }
}
