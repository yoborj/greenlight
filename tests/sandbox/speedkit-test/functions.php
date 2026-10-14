<?php
add_action( 'after_setup_theme', function () { add_theme_support( 'title-tag' ); add_theme_support( 'post-thumbnails' ); register_nav_menu( 'primary', 'Primary' ); } );
add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'test-fonts', 'https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;600&display=swap', array(), null );
	wp_enqueue_style( 'test-theme', get_template_directory_uri() . '/theme.css', array(), '1' );
	wp_enqueue_script( 'test-main', get_template_directory_uri() . '/main.js', array(), '1', false );          // head, no inline code: deferrable
	wp_enqueue_script( 'test-slider', get_template_directory_uri() . '/slider.js', array( 'jquery' ), '1', false ); // head, jQuery + inline after: must not break
	wp_add_inline_script( 'test-slider', 'window.sliderInlineRan = typeof window.TestSlider === "function";' );
	wp_enqueue_style( 'dashicons' );
} );
add_action( 'wp_head', function () { ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<!-- GA4 (fake id) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-TEST000000"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-TEST000000');window.gaInlineRan=true;</script>
<!-- Meta pixel (fake id) -->
<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','000000000000000');fbq('track','PageView');window.pixelInlineRan=true;</script>
<!-- Consent loader: must NOT be delayed -->
<script>window.consentLoaderRan=true;window.loadAnalyticsAfterConsent=function(){gtag('config','G-TEST000000');};</script>
<!-- Call tracking: must NOT be delayed -->
<script async src="https://123456.tctm.co/t.js"></script>
<?php }, 5 );
add_action( 'wp_footer', function () { ?>
<!-- Chat widget (tawk-style): should be delayed -->
<script>var Tawk_API=Tawk_API||{};(function(){var s1=document.createElement("script");s1.async=true;s1.src='https://embed.tawk.to/000000/default';document.body.appendChild(s1);window.chatInlineRan=true;})();</script>
<!-- Site code that calls gtag() on click: must keep working -->
<script>document.addEventListener('click',function(e){if(e.target.closest('.cta')){gtag('event','cta_click');window.ctaTracked=true;}});</script>
<?php } );
