<?php
/**
 * Brand assets.
 *
 * The admin menu icon must inherit the active colour scheme's icon colour —
 * #a7aaad at rest, #fff when current — so it is registered as an SVG data URI
 * drawn in currentColor. A PNG or a red icon cannot do that and would be the
 * one element on the menu that ignores the user's scheme.
 *
 * @package FolderFolio
 */

declare( strict_types=1 );

namespace FolderFolio\Admin;

final class Brand {

	/**
	 * The mark: two F's, the second rotated 180 degrees about the pair's centre,
	 * plus a six-unit tab cut at 45 degrees. Seven shapes on a 20-unit grid,
	 * every edge on a whole unit, so it snaps to the pixel at 20px.
	 */
	private const MARK = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20"><path d="M2 2H6L8 4H2Z" fill="currentColor"/><rect x="2" y="4" width="12" height="2" fill="currentColor"/><rect x="2" y="4" width="2" height="11" fill="currentColor"/><rect x="4" y="8" width="7" height="2" fill="currentColor"/><rect x="6" y="16" width="12" height="2" fill="currentColor"/><rect x="16" y="7" width="2" height="11" fill="currentColor"/><rect x="9" y="12" width="7" height="2" fill="currentColor"/></svg>';

	/**
	 * The mark as a data URI, for add_menu_page()'s $icon_url.
	 */
	public static function menu_icon(): string {
		return 'data:image/svg+xml;base64,' . base64_encode( self::MARK );
	}

	/**
	 * The raw SVG, for inlining (block icon, settings header, wizard).
	 */
	public static function mark(): string {
		return self::MARK;
	}
}
