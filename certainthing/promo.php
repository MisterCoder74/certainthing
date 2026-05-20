<?php
/**
 * CertainThing Promo Page
 * Dark themed landing page following the project's design guidelines.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CertainThing - AI-Powered Vibe Coding</title>
    <style>
        :root {
            --bg-color: #0d1117;
            --text-color: #c9d1d9;
            --accent-color: #58a6ff;
            --border-color: #30363d;
            --secondary-bg: #161b22;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji";
            margin: 0;
            padding: 0;
            line-height: 1.6;
            scroll-behavior: smooth;
        }

        a {
            color: var(--accent-color);
            text-decoration: none;
            transition: opacity 0.2s;
        }

        a:hover {
            text-decoration: underline;
            opacity: 0.8;
        }

        nav {
            position: sticky;
            top: 0;
            background-color: rgba(13, 17, 23, 0.9);
            backdrop-filter: blur(8px);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 2rem;
            display: flex;
            justify-content: center;
            gap: 2rem;
            z-index: 1000;
        }

        nav a {
            font-weight: 500;
            font-size: 0.95rem;
        }

        section {
            padding: 5rem 2rem;
            max-width: 1000px;
            margin: 0 auto;
            border-bottom: 1px solid var(--border-color);
        }

        section:last-of-type {
            border-bottom: none;
        }

        h1, h2, h3 {
            color: var(--accent-color);
            margin-top: 0;
        }

        .centered {
            text-align: center;
        }

        .features-image {
            max-width: 900px;
            width: 100%;
            height: auto;
            display: block;
            margin: 2rem auto;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }

        .btn {
            display: inline-block;
            background-color: #238636;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            font-weight: 600;
            margin-top: 1rem;
        }

        .btn:hover {
            background-color: #2ea043;
            text-decoration: none;
        }

        footer {
            padding: 4rem 2rem;
            text-align: center;
            background-color: var(--secondary-bg);
            border-top: 1px solid var(--border-color);
            color: #8b949e;
            font-size: 0.9rem;
        }

        footer p {
            margin: 0.5rem 0;
        }

        /* Responsive Breakpoints */
        @media (max-width: 830px) {
            section { padding: 3rem 1.5rem; }
            nav { gap: 1rem; padding: 1rem; }
        }

        @media (max-width: 430px) {
            nav { flex-wrap: wrap; justify-content: center; }
            h1 { font-size: 1.8rem; }
            h2 { font-size: 1.5rem; }
        }
    </style>
</head>
<body>

    <nav>
        <a href="#philosophy">Philosophy</a>
        <a href="#features">Features</a>
        <a href="#demo">Demo</a>
        <a href="#doc">Doc</a>
        <a href="#buy">Buy</a>
    </nav>

    <section id="philosophy">
        <h1>Philosophy</h1>
        <p>CertainThing is built on the belief that coding should be as intuitive as a conversation. We call it "Vibe Coding" — where your creative intent is the primary driver, and the AI handles the heavy lifting of implementation. Our goal is to bridge the gap between imagination and functional software, allowing you to iterate at the speed of thought.</p>
    </section>

    <section id="features">
        <h2>Features</h2>
        <p>Experience a new way of building applications with our core suite of AI-powered tools:</p>
        <ul style="padding-left: 1.5rem;">
            <li><strong>Conversational Chat:</strong> Describe your app in plain English.</li>
            <li><strong>Reasoning Pane:</strong> Watch the AI plan its steps in real-time for full transparency.</li>
            <li><strong>Multimodal Input:</strong> Upload images, PDFs, or paste URLs for deep context understanding.</li>
            <li><strong>One-Click Deploy:</strong> Go from concept to live app instantly.</li>
        </ul>
        <img src="certainthing_05-20-2026_01.jpg" alt="CertainThing Features Preview" class="features-image">
    </section>

    <section id="demo" class="centered">
        <h2>Demo</h2>
        <p>Watch how CertainThing transforms a simple prompt into a multi-page application.</p>
        <div style="background: #000; height: 400px; display: flex; align-items: center; justify-content: center; border-radius: 8px; border: 1px solid var(--border-color); margin-top: 2rem;">
            <p style="color: #8b949e;">[Interactive Demo Video Placeholder]</p>
        </div>
    </section>

    <section id="doc">
        <h2>Documentation</h2>
        <p>Our comprehensive technical documentation covers everything from the folder structure to advanced prompt engineering.</p>
        <p>Learn how to use the Website Analyzer to scrape existing sites for context, or how to manage sessions with our JSON-based persistence layer.</p>
        <p><a href="doc.html">Explore the Docs &rarr;</a></p>
    </section>

    <section id="buy" class="centered">
        <h2>Join the Beta</h2>
        <p>CertainThing is currently in early access. Be among the first to experience the future of AI-assisted development.</p>
        <a href="register.php" class="btn">Create Your Free Account</a>
    </section>

    <footer>
        <p>VIVACITY DESIGN AI DIVISION</p>
        <p><a href="index.html">index.html</a></p>
        <p><?php echo date('Y'); ?></p>
    </footer>

</body>
</html>
