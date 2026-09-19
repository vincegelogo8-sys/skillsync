<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="SKILLSYNC helps students find suitable research advisers through skills matching and transparent recommendations, with final approval by Admin.">
        <title>SKILLSYNC | Research Adviser Recommendations</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="landing">
        <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:bg-white focus:p-3 focus:text-gray-900">Skip to content</a>

        <div id="home" class="landing-hero">
            <img src="{{ asset('images/campus-hero.png') }}" alt="" class="landing-photo" fetchpriority="high" decoding="async">
            <div class="landing-shade" aria-hidden="true"></div>

            <header class="landing-header landing-container">
                <a href="#home" class="landing-brand" aria-label="SKILLSYNC home">
                    <x-skillsync-mark alt="" />
                    <span>
                        <span class="landing-brand-name"><strong>SKILL</strong>SYNC</span>
                        <span class="landing-brand-note">Research Adviser Recommendations</span>
                    </span>
                </a>
                <nav class="landing-nav" aria-label="Main navigation">
                    <a href="#home" aria-current="page">Home</a>
                    <a href="#about">About</a>
                    <a href="#contact">Contact</a>
                    @auth
                        <a href="{{ route('dashboard') }}" class="landing-login">Dashboard</a>
                    @else
                        <a href="{{ route('login') }}" class="landing-login">Login</a>
                    @endauth
                </nav>
            </header>

            <div id="main-content" class="landing-copy landing-container" tabindex="-1">
                <h1>Match students with research advisers</h1>
                <p>SKILLSYNC connects student research with faculty expertise through intelligent skills matching. Explore adviser recommendations based on research alignment, advising competency, preferences, and skills&mdash;with final approval by Admin.</p>
            </div>
        </div>

        <main>
            <section class="landing-features landing-container" aria-label="How SKILLSYNC supports your research">
                <article class="landing-card">
                    <h2>Research Topic Submission</h2>
                    <p>Bring your research idea forward. Submit a proposal to identify the expertise your project needs.</p>
                </article>
                <article class="landing-card">
                    <h2>Skills-Based Recommendations</h2>
                    <p>Discover faculty whose strengths align with your topic, with clear scores to guide your decision.</p>
                </article>
                <article class="landing-card">
                    <h2>Balanced Advisory Responsibilities</h2>
                    <p>See adviser availability alongside suitability. Capacity limits support manageable advising loads.</p>
                </article>
            </section>

            <section id="about" class="landing-about landing-container" aria-labelledby="about-title">
                <div>
                    <p class="landing-eyebrow">About SKILLSYNC</p>
                    <h2 id="about-title" class="landing-section-title">The right expertise.<br>A stronger research journey.</h2>
                    <p class="landing-body">Every research idea deserves informed guidance. SKILLSYNC helps students and faculty find common ground through an understandable, skills-based recommendation process.</p>
                    <p class="landing-body mt-4">Recommendations support the decision. Your department's Admin reviews and approves the final adviser assignment.</p>
                </div>
                <ol class="landing-steps">
                    <li>
                        <span class="landing-step-number" aria-hidden="true">01</span>
                        <div><h3>Start with your research</h3><p>Your proposal provides the topics, technologies, and expertise areas used in matching.</p></div>
                    </li>
                    <li>
                        <span class="landing-step-number" aria-hidden="true">02</span>
                        <div><h3>Understand the recommendations</h3><p>Compare adviser suitability through transparent criteria and individual scores.</p></div>
                    </li>
                    <li>
                        <span class="landing-step-number" aria-hidden="true">03</span>
                        <div><h3>Move forward with guidance</h3><p>Adviser availability and Admin approval help turn a recommendation into an informed assignment.</p></div>
                    </li>
                </ol>
            </section>

            <section id="contact" class="landing-contact" aria-labelledby="contact-title">
                <div class="landing-contact-inner landing-container">
                    <div>
                        <p class="landing-eyebrow">Contact &amp; support</p>
                        <h2 id="contact-title">Your research journey starts here.</h2>
                        <p class="landing-body">Need an account or help getting started? Contact your department's SKILLSYNC Admin. Student and Faculty accounts are created and provided by Admin.</p>
                    </div>
                    @auth
                        <a href="{{ route('dashboard') }}" class="landing-contact-link">Go to dashboard</a>
                    @else
                        <a href="{{ route('login') }}" class="landing-contact-link">Log in to SKILLSYNC</a>
                    @endauth
                </div>
            </section>
        </main>

        <footer class="landing-footer landing-container">
            <p>&copy; {{ date('Y') }} SKILLSYNC</p>
            <p>Research Adviser Recommendation System</p>
        </footer>
    </body>
</html>
