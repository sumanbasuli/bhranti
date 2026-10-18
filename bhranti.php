<?php
/**
 * Plugin Name: Bhranti
 * Description: Write content, update pages and manage everyday WordPress tasks with an AI assistant that works alongside your editor.
 * Version: 0.6.9
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Author: Suman Basuli
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bhranti
 *
 * @package Bhranti
 * @license GPL-2.0-or-later
 *
 * Copyright (C) 2026 Suman Basuli.
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 2 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program. If not, see <https://www.gnu.org/licenses/>.
 */

defined( 'ABSPATH' ) || exit;

define( 'BHRANTI_VERSION', '0.6.9' );
define( 'BHRANTI_FILE', __FILE__ );
// Plugin deletion is opt-in in Settings and always needs an explicit human approval.
define( 'BHRANTI_ALLOW_IRREVERSIBLE_PLUGIN_DELETION', true );

require_once __DIR__ . '/includes/class-bhranti-credentials.php';
require_once __DIR__ . '/includes/class-bhranti-lock.php';
require_once __DIR__ . '/includes/class-bhranti-workspace.php';
require_once __DIR__ . '/includes/class-bhranti-audit.php';
require_once __DIR__ . '/includes/class-bhranti-log-screen.php';
require_once __DIR__ . '/includes/class-bhranti-site.php';
require_once __DIR__ . '/includes/class-bhranti-context.php';
require_once __DIR__ . '/includes/class-bhranti-references.php';
require_once __DIR__ . '/includes/class-bhranti-memory.php';
require_once __DIR__ . '/includes/class-bhranti-chat.php';
require_once __DIR__ . '/includes/class-bhranti-progress.php';
require_once __DIR__ . '/includes/class-bhranti-diagnostics.php';
require_once __DIR__ . '/includes/class-bhranti-task.php';
require_once __DIR__ . '/includes/class-bhranti-skills.php';
require_once __DIR__ . '/includes/class-bhranti-content-quality.php';
require_once __DIR__ . '/includes/class-bhranti-render.php';
require_once __DIR__ . '/includes/class-bhranti-inspect.php';
require_once __DIR__ . '/includes/class-bhranti-forms.php';
require_once __DIR__ . '/includes/class-bhranti-content-patch.php';
require_once __DIR__ . '/includes/class-bhranti-actions.php';
require_once __DIR__ . '/includes/class-bhranti-plugins.php';
require_once __DIR__ . '/includes/class-bhranti-media.php';
require_once __DIR__ . '/includes/class-bhranti-knowledge.php';
require_once __DIR__ . '/includes/class-bhranti-commerce.php';
require_once __DIR__ . '/includes/class-bhranti-agent.php';
require_once __DIR__ . '/includes/class-bhranti-runner.php';
require_once __DIR__ . '/includes/class-bhranti-panel.php';
require_once __DIR__ . '/includes/class-bhranti-privacy.php';
require_once __DIR__ . '/includes/class-bhranti-settings.php';

Bhranti_Privacy::register();
Bhranti_Log_Screen::register();
Bhranti_Actions::register();
Bhranti_Forms::register();
Bhranti_Panel::register();
Bhranti_Settings::register();
Bhranti_Memory::register();
Bhranti_Runner::register();
Bhranti_Render::register();
register_deactivation_hook(
	BHRANTI_FILE,
	static function () {
		wp_unschedule_hook( 'bhranti_run_tick', true );
	}
);

add_action( 'init', array( 'Bhranti_Workspace', 'register_storage' ) );
add_action( 'rest_api_init', array( 'Bhranti_Workspace', 'register_routes' ) );
add_action( 'admin_menu', array( 'Bhranti_Workspace', 'register_menu' ) );
add_action( 'init', array( 'Bhranti_Audit', 'register_storage' ) );
add_action( 'rest_api_init', array( 'Bhranti_Agent', 'register_routes' ) );
add_action( 'admin_init', array( 'Bhranti_Workspace', 'privacy_policy' ) );
