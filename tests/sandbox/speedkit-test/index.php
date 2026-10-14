<!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class(); ?>>
<header>
  <a href="<?php echo esc_url( home_url( '/' ) ); ?>"><img class="logo" src="<?php echo esc_url( get_template_directory_uri() . '/logo.png' ); ?>" alt="Sandbox logo" width="160" height="40"></a>
  <nav><button class="menu-toggle" aria-label="Menu" aria-expanded="false">☰</button><ul><li><a href="/">Home</a></li><li><a href="/about/">About</a></li><li><a href="/contact/">Contact</a></li></ul></nav>
</header>
<?php $hero = get_option( 'sandbox_hero_url' ); ?>
<section class="hero" style="background-image:url('<?php echo esc_url( $hero ); ?>')">
  <div><h1>Clear Windows, Bright Homes</h1><p>Professional window cleaning across the valley. Free estimates, insured crews, spotless results.</p><a class="cta" href="#contact">Get a free estimate</a></div>
</section>
<?php if ( isset( $_GET['inlinejq'] ) ) : ?><script>jQuery(function(){ window.inlineJqRan = true; });</script><?php endif; ?>
<section class="grid"><?php foreach ( (array) get_option( 'sandbox_grid_urls', array() ) as $u ) : ?><img src="<?php echo esc_url( $u ); ?>" alt="Our work"><?php endforeach; ?></section>
<section class="embed"><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="Our process" width="560" height="315" allowfullscreen></iframe></section>
<section class="embed"><iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3022.2!2d-73.98!3d40.75!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zNDDCsDQ1JzAwLjAiTiA3M8KwNTgnNDguMCJX!5e0!3m2!1sen!2sus!4v1" title="Map"></iframe></section>
<footer id="contact" style="padding:40px 24px;background:#111;color:#fff"><p>Call (555) 010-2030 · <a style="color:#f5b800" href="tel:5550102030">Tap to call</a></p></footer>
<?php wp_footer(); ?></body></html>
