/**
 * amplifi.optimize admin UI root.
 *
 * Single entry that mounts the right screen based on the data-screen attribute
 * on the mount node (set by class-admin-menu.php).
 */
import { createRoot, StrictMode } from '@wordpress/element';
import Dashboard from './components/Dashboard';
import Scans from './components/Scans';
import ReviewQueue from './components/ReviewQueue';
import History from './components/History';
import Settings from './components/Settings';
import './styles/admin.scss';

const screens = {
	dashboard: Dashboard,
	scans: Scans,
	queue: ReviewQueue,
	history: History,
	settings: Settings,
};

const mount = document.getElementById( 'amplifi-optimize-root' );
if ( mount ) {
	const screen = mount.dataset.screen || 'dashboard';
	const Component = screens[ screen ] || Dashboard;
	const root = createRoot( mount );
	root.render(
		<StrictMode>
			<Component />
		</StrictMode>
	);
}
