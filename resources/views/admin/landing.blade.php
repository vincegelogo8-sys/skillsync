<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="SKILLSYNC Administrator Portal for managing accounts and advisory limits and monitoring research proposals, adviser requests, assignments and history.">
        <meta name="robots" content="noindex, nofollow">
        <title>SKILLSYNC | Administrator Portal</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="landing">
        <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:bg-white focus:p-3 focus:text-gray-900">Skip to content</a>

        <div id="home" class="landing-hero">
            <img src="{{ asset('images/campus-hero.png') }}" alt="" class="landing-photo" fetchpriority="high" decoding="async">
            <div class="landing-shade" aria-hidden="true"></div>

            <header class="landing-header landing-container">
                <a href="#home" class="landing-brand" aria-label="SKILLSYNC Administrator Portal home">
                    <x-skillsync-mark alt="" />
                    <span>
                        <span class="landing-brand-name"><strong>SKILL</strong>SYNC</span>
                        <span class="landing-brand-note">Administrator Portal</span>
                    </span>
                </a>
                <nav class="landing-nav" aria-label="Administrator navigation">
                    <a href="#home" aria-current="page">Home</a>
                    <a href="#about">Functions</a>
                    <a href="#access">Access</a>
                    <a href="{{ route('admin.login') }}" class="landing-login">Administrator Login</a>
                </nav>
            </header>

            <div id="main-content" class="landing-copy landing-container" tabindex="-1">
                <h1>Manage the SKILLSYNC adviser recommendation system</h1>
                <p>The Administrator Portal is for System Administrators. Manage student and faculty accounts, set advisory limits, and monitor research proposals, adviser requests, adviser assignments and history in one place.</p>
            </div>
        </div>

        <main>
            <section class="landing-features landing-container" aria-label="What administrators can do">
                <article class="landing-card">
                    <span class="landing-card-icon" aria-hidden="true">
                        <svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="11" r="4.5"/><path d="M3.5 26c.8-5 4.2-7.5 8.5-7.5s7.7 2.5 8.5 7.5"/><path d="M23 11v8M19 15h8"/></svg>
                    </span>
                    <h2>Account Management</h2>
                    <p>Create and edit student and faculty accounts, and activate or deactivate them.</p>
                </article>
                <article class="landing-card">
                    <span class="landing-card-icon" aria-hidden="true">
                        <svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 5v21M10 26h12M6 9h20"/><path d="M6 9 2 18a4 4 0 0 0 8 0zM26 9l-4 9a4 4 0 0 0 8 0z"/></svg>
                    </span>
                    <h2>Advisory Limits</h2>
                    <p>Set how many students each faculty adviser can take, so workloads stay balanced.</p>
                </article>
                <article class="landing-card">
                    <span class="landing-card-icon" aria-hidden="true">
                        <svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 26V6"/><path d="M4 26h24"/><path d="M9 21l5-6 4 3 7-9"/></svg>
                    </span>
                    <h2>Monitoring and History</h2>
                    <p>Review proposals, adviser requests, assignments and past records by course, section or adviser.</p>
                </article>
            </section>

            <section id="about" class="landing-about landing-container" aria-labelledby="about-title">
                <div>
                    <p class="landing-eyebrow">Administrator functions</p>
                    <h2 id="about-title" class="landing-section-title">Oversight for the<br>whole adviser process.</h2>
                    <p class="landing-body">Administrators keep accounts and advisory limits up to date and follow each proposal from upload to adviser assignment.</p>
                    <p class="landing-body mt-4">Adviser requests are accepted or declined by the requested faculty adviser. Administrators view this process and do not decide on it.</p>
                </div>
                <ol class="landing-steps">
                    <li>
                        <span class="landing-step-number" aria-hidden="true">01</span>
                        <div><h3>Set up accounts</h3><p>Provide student and faculty accounts, including the course, section and year level of students.</p></div>
                    </li>
                    <li>
                        <span class="landing-step-number" aria-hidden="true">02</span>
                        <div><h3>Set advisory limits</h3><p>Control the number of students each faculty adviser can have.</p></div>
                    </li>
                    <li>
                        <span class="landing-step-number" aria-hidden="true">03</span>
                        <div><h3>Monitor the process</h3><p>Follow proposals, adviser requests, assignments and history records.</p></div>
                    </li>
                </ol>
            </section>

            <section id="access" class="landing-contact" aria-labelledby="contact-title">
                <div class="landing-contact-inner landing-container">
                    <div>
                        <p class="landing-eyebrow">Authorized access only</p>
                        <h2 id="contact-title">Sign in as a System Administrator.</h2>
                        <p class="landing-body">This portal is for System Administrators only. Students and Faculty Advisers should use the main SKILLSYNC login.</p>
                    </div>
                    <a href="{{ route('admin.login') }}" class="landing-contact-link">Administrator Login</a>
                </div>
            </section>
        </main>

        <footer class="landing-footer landing-container">
            <p>&copy; {{ date('Y') }} SKILLSYNC</p>
            <p><a href="{{ url('/') }}">Go to the main site</a></p>
        </footer>
    </body>
</html>