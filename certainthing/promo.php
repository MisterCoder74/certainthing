<?php
require_once __DIR__ . '/api/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CertainThing - The AI-Powered Vibe Coder</title>
    <link rel="stylesheet" href="assets/style.css?v=<?php echo time(); ?>">
    <style>
        :root {
            --landing-accent: #58a6ff;
            --hero-bg: #0d1117;
            --section-padding: 5rem 2rem;
        }

        body {
            scroll-behavior: smooth;
        }

        /* Navigation */
        .promo-nav {
            position: sticky;
            top: 0;
            background-color: rgba(22, 27, 34, 0.8);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border-color);
            z-index: 1000;
            padding: 0.75rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .promo-nav .logo {
            text-decoration: none;
            color: var(--text-color);
        }

        .nav-links {
            display: flex;
            gap: 1.5rem;
        }

        .nav-links a {
            text-decoration: none;
            color: var(--reasoning-text);
            font-size: 0.9rem;
            font-weight: 500;
            transition: color 0.2s;
        }

        .nav-links a:hover {
            color: var(--accent-color);
        }

        /* Hero Section */
        .hero {
            padding: 8rem 2rem;
            text-align: center;
            background: radial-gradient(circle at center, #1f6feb22 0%, var(--bg-color) 70%);
            border-bottom: 1px solid var(--border-color);
        }

        .hero h1 {
            font-size: 4rem;
            margin-bottom: 1rem;
            letter-spacing: -0.02em;
        }

        .hero .tagline {
            font-size: 1.5rem;
            color: var(--reasoning-text);
            max-width: 800px;
            margin: 0 auto 2.5rem;
        }

        .cta-button {
            display: inline-block;
            padding: 1rem 2.5rem;
            background-color: var(--success-color);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: bold;
            font-size: 1.1rem;
            transition: transform 0.2s, filter 0.2s;
        }

        .cta-button:hover {
            transform: translateY(-2px);
            filter: brightness(1.1);
        }

        /* Sections */
        .section-container {
            max-width: 1100px;
            margin: 0 auto;
            padding: var(--section-padding);
        }

        .section-title {
            text-align: center;
            font-size: 2.5rem;
            margin-bottom: 3rem;
        }

        /* Philosophy Grid */
        .philosophy-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
        }

        .card {
            background-color: var(--pane-bg);
            border: 1px solid var(--border-color);
            padding: 2rem;
            border-radius: 12px;
            transition: border-color 0.3s;
        }

        .card:hover {
            border-color: var(--accent-color);
        }

        .card h3 {
            color: var(--accent-color);
            margin-top: 0;
            margin-bottom: 1rem;
        }

        .card p {
            line-height: 1.6;
            color: var(--reasoning-text);
            margin-bottom: 0;
        }

        /* Features List */
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 1.5rem;
        }

        .feature-item {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
            background-color: var(--bg-color);
            border: 1px solid var(--border-color);
            padding: 1.5rem;
            border-radius: 8px;
        }

        .feature-item .icon {
            color: var(--accent-color);
            font-size: 1.5rem;
        }

        .feature-content h4 {
            margin: 0 0 0.5rem;
        }

        .feature-content p {
            margin: 0;
            font-size: 0.95rem;
            color: var(--reasoning-text);
        }

        /* Demo Section */
        .demo-box {
            width: 100%;
            aspect-ratio: 16 / 9;
            background-color: #000;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            box-shadow: 0 20px 50px rgba(0,0,0,0.5);
        }

        .demo-placeholder {
            text-align: center;
        }

        .demo-placeholder .icon {
            font-size: 4rem;
            color: var(--accent-color);
            margin-bottom: 1rem;
            display: block;
        }

        /* Doc Section */
        .doc-section {
            background-color: var(--pane-bg);
            text-align: center;
            border-top: 1px solid var(--border-color);
            border-bottom: 1px solid var(--border-color);
        }

        .doc-link {
            color: var(--accent-color);
            text-decoration: none;
            font-weight: bold;
            font-size: 1.2rem;
            border: 1px solid var(--accent-color);
            padding: 0.75rem 2rem;
            border-radius: 6px;
            display: inline-block;
            transition: all 0.2s;
        }

        .doc-link:hover {
            background-color: var(--accent-color);
            color: white;
        }

        /* Buy Section */
        .buy-section {
            text-align: center;
        }

        .price-card {
            max-width: 400px;
            margin: 0 auto;
            border: 2px solid var(--success-color);
        }

        .price-tag {
            font-size: 3rem;
            font-weight: bold;
            margin: 1.5rem 0;
        }

        .price-tag span {
            font-size: 1rem;
            color: var(--reasoning-text);
        }

        /* Footer */
        .promo-footer {
            padding: 4rem 2rem;
            text-align: center;
            border-top: 1px solid var(--border-color);
            background-color: var(--bg-color);
            color: var(--reasoning-text);
        }

        .promo-footer a {
            color: var(--accent-color);
            text-decoration: none;
        }

        .promo-footer a:hover {
            text-decoration: underline;
        }

        /* Responsivity */
        @media (max-width: 830px) {
            .hero h1 { font-size: 3rem; }
            .nav-links { display: none; }
            .features-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 430px) {
            .hero h1 { font-size: 2.2rem; }
            .hero .tagline { font-size: 1.1rem; }
            .section-padding { padding: 3rem 1rem; }
        }
    </style>
</head>
<body>
    <nav class="promo-nav">
        <a href="#" class="logo">
            <span class="icon">✦</span> CertainThing
        </a>
        <div class="nav-links">
            <a href="#philosophy">Philosophy</a>
            <a href="#features">Features</a>
            <a href="#demo">Demo</a>
            <a href="#doc">Doc</a>
            <a href="#buy">Buy</a>
        </div>
        <a href="login.php" class="btn-small" style="text-decoration:none; padding: 0.4rem 1rem;">Login</a>
    </nav>

    <header class="hero">
        <div class="section-container">
            <h1>The <span style="color: var(--accent-color)">vibe coder</span> assistant</h1>
            <p class="tagline">Describe what you want to build in plain language, via images, files, or URLs — and get working code back in real-time.</p>
            <a href="register.php" class="cta-button">Get Started for Free</a>
        </div>
    </header>

    <section id="philosophy" class="section-container">
        <h2 class="section-title">Philosophy</h2>
        <div class="philosophy-grid">
            <div class="card">
                <h3>Conversational-first</h3>
                <p>No complex forms or configurations. Everything happens through a simple, intuitive chat interface.</p>
            </div>
            <div class="card">
                <h3>Transparent reasoning</h3>
                <p>Watch the AI "think" in real-time. Our reasoning pane shows you every planning step before a single line of code is written.</p>
            </div>
            <div class="card">
                <h3>Multimodal input</h3>
                <p>Provide context however you like. Text descriptions, UI mockups, existing source files, or even live website URLs.</p>
            </div>
            <div class="card">
                <h3>Iterative Design</h3>
                <p>Every output is a starting point. Refine, extend, and request changes naturally until your vision is perfect.</p>
            </div>
        </div>
    </section>

    <section id="features" style="background-color: var(--pane-bg); border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color);">
        <div class="section-container">
            <h2 class="section-title">Core Features</h2>
            <div class="features-grid">
                <div class="feature-item">
                    <span class="icon">💬</span>
                    <div class="feature-content">
                        <h4>Streaming Chat Interface</h4>
                        <p>Real-time conversational input with instant streaming AI responses.</p>
                    </div>
                </div>
                <div class="feature-item">
                    <span class="icon">🧠</span>
                    <div class="feature-content">
                        <h4>Live Reasoning Pane</h4>
                        <p>A dedicated trace of the AI's planning steps, providing full transparency into its process.</p>
                    </div>
                </div>
                <div class="feature-item">
                    <span class="icon">💻</span>
                    <div class="feature-content">
                        <h4>Advanced Code Renderer</h4>
                        <p>Syntax-highlighted, tabbed, and copyable code output for multi-file projects.</p>
                    </div>
                </div>
                <div class="feature-item">
                    <span class="icon">🖼️</span>
                    <div class="feature-content">
                        <h4>Multimodal Understanding</h4>
                        <p>Analyzes images, PDFs, and code files to understand your requirements deeply.</p>
                    </div>
                </div>
                <div class="feature-item">
                    <span class="icon">🌐</span>
                    <div class="feature-content">
                        <h4>Website Analyzer</h4>
                        <p>Paste a URL and CertainThing will scrape and analyze it to use as context for your build.</p>
                    </div>
                </div>
                <div class="feature-item">
                    <span class="icon">🚀</span>
                    <div class="feature-content">
                        <h4>One-Click Deploy</h4>
                        <p>Deploy your generated application to a dedicated folder instantly.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="demo" class="section-container">
        <h2 class="section-title">See it in Action</h2>
        <div class="demo-box">
            <div class="demo-placeholder">
                <span class="icon">✦</span>
                <h3>CertainThing Demo</h3>
                <p style="color: var(--reasoning-text)">Interactive demo coming soon</p>
            </div>
        </div>
    </section>

    <section id="doc" class="doc-section">
        <div class="section-container">
            <h2 class="section-title">Documentation</h2>
            <p class="tagline" style="margin-bottom: 2.5rem;">Learn everything about CertainThing, from basic usage to advanced multimodal prompts.</p>
            <a href="doc.html" class="doc-link">Read the Docs</a>
        </div>
    </section>

    <section id="buy" class="section-container buy-section">
        <h2 class="section-title">Join the Beta</h2>
        <div class="card price-card">
            <h3>Early Access</h3>
            <div class="price-tag">$0 <span>/ month</span></div>
            <p style="margin-bottom: 2rem;">Help us shape the future of vibe coding. Join our open beta today.</p>
            <a href="register.php" class="cta-button" style="width: 100%;">Create Account</a>
        </div>
    </section>

    <footer class="promo-footer">
        <div class="section-container" style="padding: 0;">
            <p>&copy; <?php echo date('Y'); ?> <span class="icon">✦</span> CertainThing</p>
            <p style="margin-top: 1rem; font-size: 0.85rem;">
                Built by <a href="https://vivacity.design" target="_blank">VIVACITY DESIGN AI DIVISION</a>
            </p>
        </div>
    </footer>
</body>
</html>
