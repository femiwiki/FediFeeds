// Show MediaWiki:Sidebar in the side column, since Citizen keeps it in a drawer.
mw.hook( 'citizen.pageAside.register' ).add( function ( data ) {
	var body = data.register( {
		id: 'fedifeeds-nav',
		label: mw.msg( 'navigation' ),
		placement: 'sticky',
		order: 10
	} );
	var menu = document.getElementById( 'citizen-main-menu' );
	if ( !body || !menu ) {
		return;
	}
	menu.querySelectorAll( '.mw-portlet' ).forEach( function ( portlet ) {
		var copy = portlet.cloneNode( true );
		copy.removeAttribute( 'id' );
		copy.querySelectorAll( '[id]' ).forEach( function ( el ) {
			el.removeAttribute( 'id' );
		} );
		copy.querySelectorAll( '.citizen-menu__heading' ).forEach( function ( el ) {
			el.classList.add( 'citizen-page-aside__heading' );
		} );
		copy.querySelectorAll( 'a[href]' ).forEach( function ( a ) {
			if ( a.pathname === location.pathname ) {
				a.setAttribute( 'aria-current', 'page' );
			}
		} );
		body.appendChild( copy );
	} );
} );
