<?php
include '../config.php';
$last_updated = "February 26, 2026";
$seo_title = "Article Writing Policy for Authors - " . $site_name;
$seo_description = "Read the complete Article Writing Policy for authors contributing to " . $site_name . ". Guidelines on SEO, content quality, AI usage, payments, internal linking, and editorial standards.";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($seo_title); ?></title>
  <meta name="description" content="<?php echo htmlspecialchars($seo_description); ?>">
  <meta name="robots" content="noindex, nofollow">
  <link rel="canonical" href="<?php echo $site_url; ?>/article-writing-policy">
  <link rel=preload href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700;800;900&family=Roboto:wght@300;400&display=swap" as=style crossorigin onload="this.onload=null;this.rel='stylesheet'"><noscript><link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;500;600;700;800;900&family=Roboto:wght@300;400&display=swap" rel=stylesheet></noscript>
  <style type="text/css">
    body{font-family:'Nunito',sans-serif;background-color:#f9f9f9;color:#333}
    *{margin:0;padding:0;box-sizing:border-box;scroll-behavior: smooth;}
    .container{max-width:900px;margin:20px auto;padding:24px;background-color:#fff;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.05)}
    h1{font-size:1.8rem;font-weight:800;text-align:center;margin-bottom:10px;color:#004aad}
    .last-updated{text-align:center;font-size:.9rem;color:#777;margin-bottom:8px}
    .update-notice{text-align:center;font-size:.9rem;color:#d35400;font-weight:600;margin-bottom:24px;padding:8px 12px;background:#fff8f0;border-left:4px solid #d35400;border-radius:4px}
    h2{font-size:1.4rem;font-weight:700;color:#004aad;border-bottom:2px solid #f0f0f0;padding-bottom:8px;margin-top:28px;margin-bottom:16px}
    h3{font-size:1.1rem;font-weight:700;color:#1a1a1a;margin-top:18px;margin-bottom:10px}
    p,li{font-size:1rem;line-height:1.7;margin-bottom:15px}
    ol{list-style-type:lower-alpha;padding-left:30px}
    ol li{margin-bottom:15px}
    ol.decimal{list-style-type:decimal}
    ol.roman{list-style-type:lower-roman}
    strong{font-weight:700;color:#111}
    .c7{text-decoration:underline;color:#004aad;font-weight:600}
    .c7:hover{text-decoration:none}
    .badge{display:inline-block;background:#e8f0fe;color:#004aad;font-size:.78rem;font-weight:700;padding:2px 9px;border-radius:12px;margin-left:6px;vertical-align:middle;letter-spacing:.03em}
    .badge.red{background:#fdecea;color:#c0392b}
    .badge.green{background:#e8f5e9;color:#1a7a2e}
    .badge.orange{background:#fff3e0;color:#b35c00}
    .highlight-box{background:#f0f5ff;border-left:4px solid #004aad;border-radius:4px;padding:14px 18px;margin:16px 0}
    .highlight-box.warning{background:#fff8f0;border-left-color:#d35400}
    .highlight-box.success{background:#f0faf2;border-left-color:#1a7a2e}
    .highlight-box p{margin-bottom:0}
    .seo-table{width:100%;border-collapse:collapse;margin:18px 0;font-size:.9rem;table-layout:fixed;word-break:break-word}
    .seo-table th{background:#004aad;color:#fff;text-align:left;padding:10px 12px;font-weight:700;word-break:break-word}
    .seo-table td{padding:9px 12px;border-bottom:1px solid #eee;vertical-align:top;word-break:break-word;white-space:normal}
    .seo-table tr:nth-child(even) td{background:#f7f9ff}
    .seo-table td:first-child{font-weight:600;width:38%;word-break:break-word;white-space:normal}
    .seo-table th:first-child{width:38%}
    @media(max-width:600px){.seo-table td,.seo-table th{padding:8px 9px}}
    .checklist{list-style:none;padding-left:0}
    .checklist li{padding-left:26px;position:relative;margin-bottom:12px}
    .checklist li::before{content:"✓";position:absolute;left:0;color:#1a7a2e;font-weight:800}
    .checklist li.cross::before{content:"✗";color:#c0392b}
    .divider{border:none;border-top:2px solid #f0f0f0;margin:28px 0}
    .toc{background:#f7f9ff;border:1px solid #dce8ff;border-radius:6px;padding:16px 20px;margin-bottom:28px}
    .toc h3{color:#004aad;margin-top:0;margin-bottom:10px;font-size:1rem}
    .toc ol{list-style-type:decimal;padding-left:22px}
    .toc ol li{margin-bottom:6px;font-size:.93rem}
    .toc a{color:#004aad;text-decoration:none;font-weight:600}
    .toc a:hover{text-decoration:underline}
    .faq-item{border:1px solid #eee;border-radius:6px;padding:14px 18px;margin-bottom:12px}
    .faq-item .faq-q{font-weight:700;color:#004aad;margin-bottom:8px;font-size:1rem}
    .faq-item p{margin-bottom:0;font-size:.97rem}
    footer-links a{display:inline-block;margin-right:8px}
  </style>
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "WebPage",
    "name": "<?php echo htmlspecialchars($seo_title); ?>",
    "description": "<?php echo htmlspecialchars($seo_description); ?>",
    "url": "<?php echo $site_url; ?>/article-writing-policy",
    "mainEntity": {
      "@type": "CreativeWork",
      "name": "Article Writing Policy"
    },
    "publisher": {
      "@type": "Organization",
      "name": "<?php echo $site_name; ?>",
      "url": "<?php echo $site_url; ?>",
      "logo": "<?php echo $site_logo; ?>"
    }
  }
  </script>
</head>
<body>
  <main role="main">
  <div class="container">

    <h1>Article Writing Policy for Authors</h1>
    <p class="last-updated"><strong>Last Updated: <?php echo $last_updated; ?></strong></p>
    <p class="update-notice">📢 This page is regularly updated with the latest official announcements and editorial guidelines.</p>

    <!-- TABLE OF CONTENTS -->
    <div class="toc">
      <h3>📋 Table of Contents</h3>
      <ol>
        <li><a href="#introduction">Introduction & Scope</a></li>
        <li><a href="#eligibility">Author Eligibility & Onboarding</a></li>
        <li><a href="#content-standards">Content Quality & Authenticity Standards</a></li>
        <li><a href="#ai-policy">AI-Generated Content Policy</a></li>
        <li><a href="#originality">Originality & Exclusivity Policy</a></li>
        <li><a href="#seo">SEO Optimization Requirements</a></li>
        <li><a href="#internal-linking">Internal Linking Guidelines</a></li>
        <li><a href="#external-linking">External Linking Guidelines</a></li>
        <li><a href="#structure">Article Structure & Formatting</a></li>
        <li><a href="#files-attachments">Files, PDFs & Attachments</a></li>
        <li><a href="#update-obligation">Author Update Obligation (1-Year Commitment)</a></li>
        <li><a href="#submission">Submission & Review Process</a></li>
        <li><a href="#payment">Payment Policy</a></li>
        <li><a href="#rights">Content Rights & Ownership</a></li>
        <li><a href="#violations">Policy Violations & Termination</a></li>
        <li><a href="#faq">Frequently Asked Questions</a></li>
        <li><a href="#contact">Contact & Editorial Team</a></li>
      </ol>
    </div>

    <!-- SECTION 1 -->
    <h2 id="introduction">1. Introduction &amp; Scope</h2>
    <ol>
      <li><?php echo $site_name; ?> ("we", "us", or "our") is a dedicated platform providing up-to-date information on government jobs, PSU vacancies, bank recruitments, private sector openings, and related career resources across India. This Article Writing Policy ("Policy") governs all content contributions made by registered authors, freelance writers, and content contributors ("Authors") to our platform at <a href="<?php echo $site_url; ?>" class="c7"><?php echo $site_url; ?></a>.</li>
      <li>By submitting any article, draft, or content piece to <?php echo $site_name; ?>, you confirm that you have read, understood, and agreed to be legally and professionally bound by every provision of this Policy in its entirety. No exceptions will be made unless explicitly communicated in writing by the Editorial Team.</li>
      <li>This Policy is designed to uphold the highest standards of editorial integrity, SEO performance, reader trust, and legal compliance. It supplements and is read alongside our <a href="<?php echo $site_url; ?>/editorial-policy" class="c7">Editorial Policy</a>, <a href="<?php echo $site_url; ?>/terms-of-service" class="c7">Terms of Service</a>, and <a href="<?php echo $site_url; ?>/privacy-policy" class="c7">Privacy Policy</a>.</li>
      <li><?php echo $site_name; ?> reserves the right to amend this Policy at any time. Authors will be notified of significant changes via email. Continued submission of content after notification constitutes acceptance of the revised Policy.</li>
    </ol>

    <!-- SECTION 2 -->
    <h2 id="eligibility">2. Author Eligibility &amp; Onboarding</h2>
    <ol>
      <li><strong>Who Can Contribute:</strong> Any individual with demonstrated knowledge of government recruitment processes, banking, public sector undertakings, or related career topics may apply to become a contributing author. A portfolio or sample of prior published work is required during the application phase.</li>
      <li><strong>Verification:</strong> Authors must complete identity and contact verification before their first article is published. This includes providing a valid email address, full legal name, and a brief professional bio that will appear on their author profile.</li>
      <li><strong>Author Agreement:</strong> Prior to onboarding, authors must digitally sign or formally acknowledge the Author Agreement which encompasses this Policy, payment terms, content rights, and the one-year update obligation described in Section 11.</li>
      <li><strong>Conflict of Interest:</strong> Authors must disclose any personal, financial, or professional relationship with entities they write about. Undisclosed conflicts of interest are grounds for immediate removal of content and termination of the author relationship.</li>
      <li><strong>Probation Period:</strong> New authors serve a probation period for their first three (3) published articles during which the Editorial Team will provide additional review and detailed feedback. Full author privileges are granted after satisfactory completion of this period.</li>
    </ol>

    <!-- SECTION 3 -->
    <h2 id="content-standards">3. Content Quality &amp; Authenticity Standards</h2>

    <div class="highlight-box success">
      <p><strong>Core Principle:</strong> Every article published on <?php echo $site_name; ?> must be accurate, current, well-researched, and genuinely useful to job seekers across India. Reader trust is our most valuable asset.</p>
    </div>

    <ol>
      <li><strong>Accuracy First:</strong> All facts, figures, dates, vacancy counts, eligibility criteria, pay scales, and deadlines must be sourced directly from official government notifications, press releases, official websites, or verified secondary sources. Authors are personally responsible for the factual accuracy of every claim in their submitted article.</li>
      <li><strong>Current &amp; Latest Topics Only:</strong> Articles must be based on <strong>current and recently announced</strong> information. Articles about outdated vacancies, expired notifications, or events that occurred more than 30 days prior (unless it is an evergreen reference article) will be rejected unless the author obtains prior editorial approval. We do not accept articles about opportunities that are no longer open or relevant to our readers' active job search.</li>
      <li><strong>Clarity &amp; Depth:</strong> Content must be written in clear, simple English (or approved Hinglish where applicable) that is accessible to a broad range of readers including those from Tier-2 and Tier-3 cities. Articles should provide complete and detailed coverage of the subject — not surface-level summaries. Minimum article length is <strong>1,000 words</strong>; recommended length is 1,500–2,500 words depending on the topic.</li>
      <li><strong>No Speculation or Opinion Masquerading as Fact:</strong> Authors must clearly distinguish between confirmed official information and expected/anticipated information. Speculative statements must be explicitly labeled as such (e.g., "Based on the previous year's pattern, it is expected that…"). Presenting unverified predictions as facts is a serious policy violation.</li>
      <li><strong>Overview Table (Mandatory – First Fold):</strong> Every article must include a structured <strong>Overview Table</strong> placed within the first fold of the article (before the first main H2 section). This table should summarize the most critical details of the subject at a glance — for example, for a government job article: Organization Name, Post Name, Total Vacancies, Application Start/End Date, Eligibility, Official Website. This is a non-negotiable requirement as it directly supports our ranking performance and Answer Engine Optimization (AEO) goals.</li>
      <li><strong>Freshness Signal (Mandatory):</strong> All articles must include the following line (or its Hindi equivalent) near the top of the article, directly after the introductory paragraph:
        <div class="highlight-box" style="margin-top:10px">
          <p><em>"This page is regularly updated with the latest official announcements."</em><br>
          <strong>Hindi:</strong> <em>"इस पेज को आधिकारिक अपडेट के अनुसार नियमित रूप से अपडेट किया जा रहा है।"</em></p>
        </div>
      </li>
      <li><strong>User Intent Statement (Mandatory):</strong> Within the introductory section (first 150 words), authors must include a clear statement of what the reader will find on the page. Example: <em>"On this page, you can check the latest exam date, exam city, and direct admit card download link."</em> This statement should be naturally written and match the specific content of the article.</li>
      <li><strong>No Plagiarism:</strong> All content must be 100% original. Plagiarism — including paraphrasing, spinning, or closely rewording another source's article without transformative value — is strictly prohibited and will result in immediate termination. See Section 5 for the full Originality &amp; Exclusivity Policy.</li>
    </ol>

    <!-- SECTION 4 -->
    <h2 id="ai-policy">4. AI-Generated Content Policy <span class="badge orange">Strict</span></h2>

    <div class="highlight-box warning">
      <p><strong>⚠️ Important:</strong> <?php echo $site_name; ?> does <strong>not</strong> accept purely AI-generated articles. The use of AI writing tools is permitted only as an assistive tool under strict conditions outlined below.</p>
    </div>

    <ol>
      <li><strong>Purely AI-Written Content is Rejected:</strong> Articles that are generated entirely or predominantly by AI tools such as ChatGPT, Gemini, Claude, Copilot, or any other Large Language Model (LLM), without significant human authorship, will be rejected outright. Our editorial systems and team perform AI detection checks on all submitted content.</li>
      <li><strong>AI as an Assistive Tool Only:</strong> Authors may use AI tools to assist with brainstorming, outlining, grammar checks, or rephrasing individual sentences. However, the core research, factual verification, narrative structure, original insights, and the majority of written prose must be the author's own human work.</li>
      <li><strong>Mandatory Human Touch-Up:</strong> If any AI-generated phrases, sentences, or paragraphs are used — even partially — the author must substantively rewrite, re-research, and humanize those sections to reflect authentic human authorship. This means:
        <ol class="roman">
          <li>Replacing generic AI phrasing with specific, verified, factual claims drawn from official sources.</li>
          <li>Adding personal professional insight, context, or analysis that an AI cannot independently provide.</li>
          <li>Ensuring the article's tone, voice, and structure are consistent with human writing and the author's established style.</li>
          <li>Verifying every factual claim independently — AI tools frequently hallucinate data. Authors are responsible for any factual errors regardless of the tool used.</li>
        </ol>
      </li>
      <li><strong>AI Disclosure:</strong> Authors who use AI as an assistive tool in any capacity must disclose this in their submission notes to the editorial team. This disclosure is for internal tracking only and does not affect the publication decision, provided all other AI policy conditions are met.</li>
      <li><strong>Consequences of Violation:</strong> Submitting content that is found to be predominantly AI-generated without adequate human editing is a serious policy violation. The article will be immediately rejected or removed if already published. Repeated violations will result in permanent termination of the author's account and forfeiture of any pending payments for the affected articles.</li>
    </ol>

    <!-- SECTION 5 -->
    <h2 id="originality">5. Originality &amp; Exclusivity Policy <span class="badge red">Strict</span></h2>
    <ol>
      <li><strong>Exclusive First Publication:</strong> All articles submitted to <?php echo $site_name; ?> must be submitted for <strong>first and exclusive publication</strong>. Authors warrant that the submitted article has not been previously published on any other website, blog, platform, social media channel, or medium in any language.</li>
      <li><strong>No Cross-Posting After Publication:</strong> Once an article is published on <?php echo $site_name; ?>, the author is <strong>strictly prohibited</strong> from republishing, syndicating, mirroring, copying, or paraphrasing that article — in whole or in part — on any other platform, website, blog, Quora, Medium, LinkedIn, Telegram channel, WhatsApp groups, or any other digital or print medium without prior written consent from <?php echo $site_name; ?>. This restriction applies indefinitely and does not expire after the one-year update commitment period ends.</li>
      <li><strong>No Duplicate Submissions:</strong> Authors must not submit the same article or a substantially similar version of an article to multiple editors or sections of <?php echo $site_name; ?> simultaneously. Each submission must be unique and purpose-built for the assigned topic or category.</li>
      <li><strong>Plagiarism Detection:</strong> All articles are run through plagiarism detection tools prior to publication. A similarity score above 15% against external sources may lead to rejection. Authors must ensure that any quoted material from official sources (such as government notifications) is clearly attributed and kept to a minimum necessary for reference purposes.</li>
      <li><strong>Social Sharing is Encouraged:</strong> Authors are encouraged to share links to their published articles on personal social media profiles and professional networks. Sharing a <em>link</em> to the article is permitted and appreciated. Reproducing the article content itself is not permitted.</li>
    </ol>

    <!-- SECTION 6 -->
    <h2 id="seo">6. SEO Optimization Requirements <span class="badge">Mandatory</span></h2>

    <p>Search Engine Optimization is central to the success of every article on <?php echo $site_name; ?>. Authors are required to follow these SEO guidelines without exception. Articles that do not meet these standards will be returned for revision before publication.</p>

    <h3>6.1 Primary Keyword Placement</h3>
    <p>Each article must have one designated <strong>Primary Keyword</strong> agreed upon with the editorial team before writing begins. The primary keyword must appear in all of the following locations:</p>

    <table class="seo-table">
      <tr>
        <th>Placement Location</th>
        <th>Requirement</th>
      </tr>
      <tr>
        <td>Page Title (H1)</td>
        <td>Primary keyword must appear in the article title — ideally at or near the beginning.</td>
      </tr>
      <tr>
        <td>URL Slug</td>
        <td>The slug must contain the primary keyword in lowercase, hyphenated format (e.g., <code>ssc-chsl-2025-notification</code>).</td>
      </tr>
      <tr>
        <td>Meta Description</td>
        <td>The primary keyword must appear naturally within the 150–160 character meta description.</td>
      </tr>
      <tr>
        <td>First Paragraph (within 100 words)</td>
        <td>The primary keyword must appear within the first 100 words of the article body — naturally and contextually.</td>
      </tr>
      <tr>
        <td>H2 Headings (Exact + Partial)</td>
        <td>At least <strong>two H2 headings</strong> must contain the primary keyword: one as an <strong>exact match</strong> (e.g., "SSC CHSL 2025 Notification") and one as a <strong>partial match</strong> (e.g., "CHSL 2025 Application Process and Important Dates").</td>
      </tr>
      <tr>
        <td>Article Body</td>
        <td>The primary keyword must appear <strong>naturally 1–2 additional times</strong> within the body content. Do not force or stuff the keyword. Natural usage only.</td>
      </tr>
      <tr>
        <td>FAQ Section</td>
        <td>At least one FAQ question must incorporate the primary keyword naturally. (See FAQ requirements in Section 6.4.)</td>
      </tr>
    </table>

    <h3>6.2 Secondary Keyword Placement</h3>
    <p>Each article may have up to <strong>3–4 secondary keywords</strong> agreed upon with the editorial team. Each secondary keyword must adhere to the following rules:</p>
    <ol>
      <li>Any single secondary keyword must not appear more than <strong>3–4 times</strong> across the entire article (including title, headings, body, and FAQ combined).</li>
      <li>Each secondary keyword should appear in at least one of the following — <strong>the Title (if applicable)</strong>, an <strong>H2 heading</strong>, or an <strong>FAQ question or answer</strong>.</li>
      <li>Secondary keywords must be used naturally and contextually. Keyword stuffing in any form is a violation of this Policy and will result in article rejection.</li>
    </ol>

    <h3>6.3 Semantic Heading Hierarchy</h3>
    <p>All articles must strictly follow semantic HTML heading hierarchy. This is both an SEO requirement and an accessibility standard:</p>
    <ul class="checklist">
      <li>One and only one <strong>H1</strong> per article — this is the article title.</li>
      <li><strong>H2</strong> headings for all major sections (e.g., Eligibility, Important Dates, How to Apply, Selection Process, FAQ).</li>
      <li><strong>H3</strong> for sub-sections within an H2 section.</li>
      <li><strong>H4</strong> for sub-sub-sections only where genuinely necessary.</li>
      <li class="cross">Never skip heading levels (e.g., do not jump from H2 directly to H4).</li>
      <li class="cross">Never use heading tags for styling purposes — only for structural hierarchy.</li>
      <li class="cross">Never use bold text or large font styling as a substitute for heading tags.</li>
    </ul>

    <h3>6.4 FAQ Section Requirements (Mandatory)</h3>
    <ol>
      <li>Every article must include a <strong>FAQ section</strong> with a minimum of <strong>4 questions and a maximum of 6 questions</strong>.</li>
      <li>At least one FAQ question must directly incorporate the primary keyword.</li>
      <li>At least <strong>one FAQ question must be written in Hinglish</strong> (a natural blend of Hindi and English as commonly typed by Indian readers in search queries). Example: <em>"SSC CHSL 2025 ke liye kaise apply kare?"</em></li>
      <li>FAQ questions should reflect real user search queries — use question formats such as "What is…", "How to…", "When will…", "Kaise…", "Kab aayega…" etc.</li>
      <li>FAQ answers must be concise (50–100 words per answer), factually accurate, and directly responsive to the question.</li>
      <li>The FAQ section must use proper structured markup (ideally Schema.org FAQPage schema added by the editorial team or author in consultation).</li>
    </ol>

    <h3>6.5 Things to Strictly Avoid (SEO)</h3>
    <ul class="checklist">
      <li class="cross">Using an old intro without an update signal or freshness indicator.</li>
      <li class="cross">Repeating the same anchor text for multiple different internal links.</li>
      <li class="cross">Adding more than <strong>7 internal links</strong> in a single article.</li>
      <li class="cross">Keyword stuffing — using the primary keyword more than the permitted frequency.</li>
      <li class="cross">Thin content — articles that are mere summaries without original depth or added value.</li>
      <li class="cross">Missing or duplicate meta descriptions.</li>
      <li class="cross">Unoptimized images — all images must have descriptive, keyword-relevant alt text.</li>
    </ul>

    <!-- SECTION 7 -->
    <h2 id="internal-linking">7. Internal Linking Guidelines <span class="badge">Mandatory</span></h2>

    <div class="highlight-box">
      <p><strong>Rule:</strong> Each article must contain a minimum of <strong>4 internal links</strong> and must not exceed <strong>7 internal links</strong> in total. All internal links must be placed naturally within the content — never forced or listed at the bottom as a link dump.</p>
    </div>

    <ol>
      <li><strong>Category Pages (Minimum 1, Recommended 2–3):</strong> Each article must naturally interlink to at least <strong>one relevant category page</strong> on <?php echo $site_name; ?>. For example, a bank job article should link to the Banking Jobs category. Where naturally possible, interlink to 2–3 closely related category pages to reinforce topical authority. The anchor text must be descriptive and relevant (e.g., "latest bank jobs" not just "click here").</li>
      <li><strong>State Pages (Minimum 1, Recommended 2–3):</strong> Where the opportunity or content is relevant to one or more Indian states, the article must naturally interlink to at least <strong>one relevant State Page</strong> on the platform. For example, a vacancy open only to Rajasthan residents should link to the Rajasthan Government Jobs page. Interlink to 2–3 state pages if the content is genuinely relevant to multiple states.</li>
      <li><strong>Related Blog Posts (Minimum 1, Recommended 2–3):</strong> Each article must include at least <strong>one contextual internal link</strong> to another published blog post or article on <?php echo $site_name; ?> that is topically related. These links must make logical sense to the reader — they should add value, not be inserted arbitrarily. Use varied, descriptive, and natural anchor texts for each link.</li>
      <li><strong>Tool Pages (Minimum 1, Recommended 1–2):</strong> Each article must naturally include at least <strong>one link to a relevant Tool page</strong> on <?php echo $site_name; ?>. Examples include our Salary Calculator, Exam Preparation tools, Application Status Tracker, or Cutoff Marks calculator. The link must be contextually placed and genuinely useful to the reader at that point in the article.</li>
      <li><strong>No Repeated Anchor Texts:</strong> Do not use the same anchor text for more than one internal link within the same article. Vary the anchor texts to appear natural and to serve different user intents.</li>
      <li><strong>No Link Stuffing:</strong> The total number of internal links in one article must not exceed <strong>7</strong>. Quality and relevance of links matter far more than quantity. Links should enhance the reader experience, not disrupt it.</li>
    </ol>

    <!-- SECTION 8 -->
    <h2 id="external-linking">8. External Linking Guidelines <span class="badge">Mandatory</span></h2>
    <ol>
      <li><strong>Official Sources Required:</strong> Every article must include at least <strong>2–3 external links</strong> to official, authoritative, and government-recognized sources. These may include official government recruitment board websites (e.g., ssc.gov.in, ibps.in, upsc.gov.in), official notification PDFs hosted on government servers, or official ministry/department websites.</li>
      <li><strong>No Broken Links:</strong> Before submitting the article, the author must personally verify that every external link is live and returns a valid page (HTTP 200 response). Links that return a 404 Not Found, 403 Forbidden, or redirect to an unrelated page will cause the article to be rejected or returned for correction. Do not link to URLs that may expire soon or are session-dependent.</li>
      <li><strong>Crawlable Links Only:</strong> External links must be crawlable by search engine bots. Do not link to pages that are behind login walls, CAPTCHA verification, or noindex/nofollow directives from the source side, as these do not contribute to SEO authority signals.</li>
      <li><strong>No Affiliate or Sponsored External Links:</strong> Authors must not include any affiliate links, referral links, sponsored links, or paid promotional links in their articles. <?php echo $site_name; ?> does not allow any form of undisclosed commercial linking in editorial content.</li>
      <li><strong>No Links to Competitor Platforms:</strong> Authors must not include external links that direct readers to competing job portals, career information websites, or platforms that serve as direct competitors to <?php echo $site_name; ?>. The editorial team reserves the right to remove such links without notice.</li>
      <li><strong>PDF Links Must Be Official:</strong> If you reference a PDF document (such as an official notification), it must be linked from the official government website directly. Do not link to third-party hosted copies of official PDFs. (See also Section 10 on File Upload Policy.)</li>
    </ol>

    <!-- SECTION 9 -->
    <h2 id="structure">9. Article Structure &amp; Formatting Standards</h2>
    <ol>
      <li><strong>Title (H1):</strong> One clear, keyword-rich title that accurately describes the article. The title should ideally include the year (e.g., "2025") for time-sensitive content, and the primary keyword positioned as early as possible.</li>
      <li><strong>Introduction (First 150 words):</strong> Must include:
        <ol class="roman">
          <li>The primary keyword within the first 100 words.</li>
          <li>The freshness/update signal line.</li>
          <li>The user intent statement telling readers exactly what they will find on the page.</li>
          <li>A brief 2–3 sentence contextual overview of the topic.</li>
        </ol>
      </li>
      <li><strong>Overview Table (Mandatory – First Fold):</strong> Immediately after the introduction, before any H2 section, authors must place a complete overview table. This is a non-negotiable requirement. (See Section 3.5 for full details.)</li>
      <li><strong>Body Sections:</strong> Use meaningful H2 and H3 headings to organize content logically. Standard sections for a government job article include (but are not limited to): Important Dates, Vacancy Details, Eligibility Criteria, Application Fee, How to Apply Online, Selection Process, Syllabus/Exam Pattern, Admit Card, Result, Cut Off, and Official Notification.</li>
      <li><strong>FAQs (Mandatory):</strong> The FAQ section must appear near the end of the article, before any closing note or disclaimer. Minimum 4, maximum 6 questions. (See Section 6.4 for full FAQ requirements.)</li>
      <li><strong>Paragraph Length:</strong> Keep paragraphs short — 2–4 sentences maximum. Long unbroken paragraphs hurt readability and SEO. Use bullet points and numbered lists where they add clarity, but do not convert all content into lists at the expense of readable prose.</li>
      <li><strong>Image Optimization:</strong> Any images included must have:
        <ol class="roman">
          <li>Descriptive, keyword-relevant alt text.</li>
          <li>A compressed file size (ideally under 100KB) to maintain page speed.</li>
          <li>A clear, professional appearance relevant to the article content.</li>
          <li>No copyrighted images without proper licensing. Use only original, licensed, or Creative Commons images.</li>
        </ol>
      </li>
      <li><strong>Language &amp; Tone:</strong> Write in clear, professional, and friendly English. Complex jargon should be explained in simple terms. Use active voice wherever possible. Hindi or Hinglish elements are permitted where they genuinely improve clarity for the target audience, particularly in FAQ questions or user-intent statements.</li>
      <li><strong>Tables:</strong> Use HTML tables or CMS table blocks (not screenshots of tables) for all structured data such as vacancy tables, eligibility criteria, pay scale details, and important dates. Tables must be properly headed with column names.</li>
    </ol>

    <!-- SECTION 10 -->
    <h2 id="files-attachments">10. Files, PDFs &amp; Attachments Policy <span class="badge red">Strict</span></h2>

    <div class="highlight-box warning">
      <p><strong>⚠️ Non-Compliance Alert:</strong> Uploading PDFs or files from unauthorized third-party sources is a violation of this Policy and may result in legal liability. Always verify the source before referencing or uploading any file.</p>
    </div>

    <ol>
      <li><strong>Permitted Sources Only:</strong> All PDF files, official notification documents, form attachments, admit card files, result sheets, syllabus documents, or any other downloadable files referenced or embedded in an article must come from <strong>one of two approved sources only</strong>:
        <ol class="roman">
          <li><strong>Official Government / Organizational Websites:</strong> Link directly to the file hosted on the official recruitment board, ministry, department, or public sector organization's website (e.g., .gov.in, .nic.in, .ac.in, or verified official domains). These links must be live and crawlable.</li>
          <li><strong>Our Own Server:</strong> Files that need to be embedded or downloaded through <?php echo $site_name; ?>'s platform must be uploaded directly to our server via the designated media upload system. Only the editorial team or authorized authors with upload permissions may add files to our server. All uploaded files must be verified as originating from official sources before upload.</li>
        </ol>
      </li>
      <li><strong>Prohibited Sources:</strong> Under no circumstances may authors link to or embed PDFs or files from third-party websites, unofficial document-sharing platforms (e.g., Scribd, Google Drive shared by individuals, Dropbox, Telegram), or any unverified source. Such links will be removed by the editorial team, and repeated violations will result in policy action.</li>
      <li><strong>File Naming:</strong> When uploading files to our server, use a clear, descriptive file name that reflects the content (e.g., <code>ssc-chsl-2025-official-notification.pdf</code>). Do not use generic names like <code>document1.pdf</code> or names containing special characters other than hyphens.</li>
      <li><strong>No Pirated or Reproduced Documents:</strong> Authors must not upload reproductions, scanned copies, screenshots, or reconstructions of copyrighted government documents. Only original, officially published files may be referenced.</li>
      <li><strong>Link Verification Before Submission:</strong> Before submitting an article, the author must personally click and verify that all file links (both external official links and server-hosted files) are accessible, load correctly, and point to the intended document.</li>
    </ol>

    <!-- SECTION 11 -->
    <h2 id="update-obligation">11. Author Update Obligation — 1-Year Commitment <span class="badge red">Mandatory</span></h2>

    <div class="highlight-box warning">
      <p><strong>⚠️ This is a binding contractual commitment.</strong> By submitting an article, the author agrees to monitor and update that article for a period of one (1) full year from the date of publication. This obligation is a fundamental condition of authorship at <?php echo $site_name; ?>.</p>
    </div>

    <ol>
      <li><strong>Nature of the Obligation:</strong> Job-related content — especially government recruitment articles — is inherently dynamic. Vacancies change, exam dates are revised, admit card links go live, results are declared, cutoffs are released, and application windows open and close. Authors are required to keep their published articles current throughout the one-year commitment period.</li>
      <li><strong>What Must Be Updated:</strong> The following types of changes must be reflected in the article as soon as the official information is available:
        <ol class="roman">
          <li>Changes in total vacancy count or revised vacancy notifications.</li>
          <li>Revised application start/end dates or extension of deadlines.</li>
          <li>Correction windows, modification periods, or re-open of applications.</li>
          <li>Changes in eligibility criteria, age relaxation, or educational qualifications.</li>
          <li>Exam date announcements, postponements, or rescheduling.</li>
          <li>Exam city intimation and admit card release.</li>
          <li>Answer key release and objection windows.</li>
          <li>Result declaration, merit list publication, and cut-off marks release.</li>
          <li>Document verification, interview schedule, and final selection list.</li>
          <li>Any official corrigendum or addendum to the original notification.</li>
        </ol>
      </li>
      <li><strong>Update Timeline:</strong> Authors must update the article within <strong>48 hours</strong> of an official announcement being made by the recruiting organization. For time-sensitive updates (such as admit card download links going live or exam rescheduling), updates must be made within <strong>24 hours</strong>.</li>
      <li><strong>How to Submit Updates:</strong> Authors must notify the editorial team via their designated author communication channel whenever they update an article. The notification must include the nature of the change and the official source from which the information was obtained. All updates are subject to editorial review before going live.</li>
      <li><strong>Monitoring Responsibility:</strong> It is the author's responsibility to proactively monitor official websites, press releases, and authenticated news sources related to their published articles. Authors must not wait to be informed by the editorial team of updates — they must independently track developments.</li>
      <li><strong>Failure to Update:</strong> If an author fails to update their article within the required timeline, the editorial team reserves the right to (i) update the article using in-house editorial resources, (ii) reassign the article to another author, and/or (iii) deduct or withhold payment for that article for the month in which the failure occurred. Repeated failures to maintain updates will result in termination of the author relationship.</li>
      <li><strong>After the 1-Year Period:</strong> Once the one-year commitment period for a specific article ends, responsibility for maintaining that article's accuracy transfers fully to the <?php echo $site_name; ?> editorial team. However, authors are encouraged to continue contributing updates on a voluntary basis for articles they originally wrote.</li>
    </ol>

    <!-- SECTION 12 -->
    <h2 id="submission">12. Submission &amp; Editorial Review Process</h2>
    <ol>
      <li><strong>Pre-Submission Checklist:</strong> Before submitting any article, authors must self-verify compliance with all sections of this Policy. A pre-submission checklist will be provided by the editorial team and must be completed and attached with every submission. Incomplete submissions will be returned without review.</li>
      <li><strong>Submission Format:</strong> Articles must be submitted through the designated Author Portal or submission system as specified during onboarding. Submissions via email, WhatsApp, or other informal channels are not accepted unless specifically authorized by the editorial team for a specific case.</li>
      <li><strong>Draft Ownership:</strong> Until an article is formally accepted and published, the draft remains the author's intellectual property. Upon publication (see Section 14), content rights transfer as outlined in the Content Rights &amp; Ownership section.</li>
      <li><strong>Editorial Review Turnaround:</strong> The editorial team aims to review and respond to submitted articles within <strong>5 business days</strong>. Authors will receive one of the following outcomes: (i) Accepted for publication as-is, (ii) Accepted pending minor revisions (author must revise within 48 hours), (iii) Returned for major revision (author must revise within 5 business days), or (iv) Rejected with written explanation.</li>
      <li><strong>Revision Rounds:</strong> Authors are permitted a maximum of <strong>two (2) revision rounds</strong> per article. If the article still does not meet our standards after two rounds of revision, it will be rejected and the author will be notified with detailed feedback. Payment is not issued for rejected articles.</li>
      <li><strong>Editorial Changes:</strong> The editorial team reserves the right to make minor edits to published articles for grammar, style, SEO optimization, formatting, or factual corrections without requiring author approval for minor changes. For substantive changes, the author will be informed. Authors may not demand reversion of editorial changes that improve accuracy or SEO.</li>
      <li><strong>Publication Date:</strong> The editorial team determines the publication date. Authors may suggest a preferred publication date, but the final scheduling decision rests with the editorial team based on content calendar priorities.</li>
    </ol>

    <!-- SECTION 13 -->
    <h2 id="payment">13. Payment Policy <span class="badge green">Transparent</span></h2>

    <div class="highlight-box success">
      <p><strong>Clarity &amp; Fairness:</strong> <?php echo $site_name; ?> is committed to transparent, timely, and fair compensation for all contributing authors. All payment terms are agreed upon in writing during onboarding and are subject to the conditions outlined in this section.</p>
    </div>

    <ol>
      <li><strong>Monthly Payment Cycle:</strong> All payments to authors are processed at the <strong>end of each calendar month</strong> — not per article and not immediately upon publication. Authors will receive a consolidated payment for all articles published and fully accepted during that calendar month.</li>
      <li><strong>Payment Processing Date:</strong> Payments are initiated on or before the <strong>last working day</strong> of each month. Processing may take 2–5 additional business days to reflect in the author's bank account depending on their bank and payment method.</li>
      <li><strong>Per-Article Rate:</strong> The per-article payment rate is agreed upon individually during the author onboarding process and confirmed in writing via the Author Agreement. Rates may vary based on article type, word count, research complexity, and the author's experience level. Rates are reviewed periodically and authors will be notified of any changes with at least 30 days' advance notice.</li>
      <li><strong>Payment Conditions — Article Must Be:</strong>
        <ol class="roman">
          <li>Fully published and live on <?php echo $site_name; ?> (not merely submitted or pending review).</li>
          <li>Compliant with all guidelines in this Policy (non-compliant articles that required substantial editorial rework may attract a reduced rate as communicated by the editorial team).</li>
          <li>Not subsequently removed or unpublished due to policy violations, inaccuracies, or legal issues attributable to the author.</li>
        </ol>
      </li>
      <li><strong>Payment Deductions:</strong> The editorial team may deduct from monthly payments in the following cases:
        <ol class="roman">
          <li>Articles found to be substantially AI-generated after publication (full deduction for affected articles).</li>
          <li>Articles containing factual errors that required significant editorial correction (partial deduction as determined by the editorial team).</li>
          <li>Failure to fulfill the mandatory update obligation within the required timeframe (deduction per instance as per the Author Agreement).</li>
          <li>Articles removed due to plagiarism, exclusivity violations, or external link policy breaches (full deduction + potential further action).</li>
        </ol>
      </li>
      <li><strong>Payment Method:</strong> Payments are made via bank transfer (NEFT/IMPS) to the author's verified Indian bank account. International transfers are not supported at this time. Authors must provide accurate bank details during onboarding; <?php echo $site_name; ?> is not responsible for failed payments due to incorrect bank details provided by the author.</li>
      <li><strong>Tax Compliance:</strong> Authors are responsible for their own tax obligations on income received from <?php echo $site_name; ?>. Where applicable under Indian tax law, TDS (Tax Deducted at Source) will be deducted as per prevailing rates. A TDS certificate will be issued as required. Authors should consult a tax professional for guidance on their individual obligations.</li>
      <li><strong>Payment Disputes:</strong> Authors who believe there is a discrepancy in their monthly payment must raise a written dispute with the editorial team within <strong>7 calendar days</strong> of the payment date. Disputes raised after this window will not be considered for that payment cycle.</li>
      <li><strong>Advance Payments:</strong> <?php echo $site_name; ?> does not offer advance payments, upfront fees, or per-article payments outside the monthly cycle. No exceptions will be made.</li>
    </ol>

    <!-- SECTION 14 -->
    <h2 id="rights">14. Content Rights &amp; Ownership</h2>
    <ol>
      <li><strong>Transfer Upon Publication:</strong> Upon formal acceptance and publication of an article on <?php echo $site_name; ?>, the author grants <?php echo $site_name; ?> an <strong>exclusive, perpetual, irrevocable, royalty-free, worldwide license</strong> to publish, reproduce, distribute, modify, adapt, translate, and display the article in any medium or format, both current and future.</li>
      <li><strong>Moral Rights:</strong> Authors retain their moral rights of attribution. Published articles will display the author's name and bio as agreed during onboarding. Authors may not demand removal of their name from a published article without simultaneously requesting removal of the entire article.</li>
      <li><strong>No Republication Without Written Consent:</strong> The exclusive license granted above means the author may not republish, syndicate, or otherwise use the published article content on any other platform without prior written consent from <?php echo $site_name; ?>. This is a perpetual restriction and does not expire. (See also Section 5.)</li>
      <li><strong>Modifications:</strong> <?php echo $site_name; ?> reserves the right to modify, update, translate, expand, or condense published articles at any time for editorial, SEO, accuracy, or legal compliance reasons. Authors will be informed of major structural changes.</li>
      <li><strong>Removal of Content:</strong> <?php echo $site_name; ?> reserves the right to unpublish or permanently delete any article at its sole discretion, including where content is found to violate this Policy, applicable laws, or the platform's editorial standards. No compensation will be payable for articles removed due to policy violations attributable to the author.</li>
    </ol>

    <!-- SECTION 15 -->
    <h2 id="violations">15. Policy Violations &amp; Consequences</h2>

    <p>Violations of this Policy are taken seriously. The consequence for any violation depends on its severity, as outlined below:</p>

    <table class="seo-table">
      <tr>
        <th>Violation Type</th>
        <th>Consequence</th>
      </tr>
      <tr>
        <td>Purely AI-Generated Content Submission</td>
        <td>Article rejected / removed. Warning issued. Repeated violation = account termination + forfeiture of payment.</td>
      </tr>
      <tr>
        <td>Plagiarism / Copying from Other Platforms</td>
        <td>Immediate article removal. Full payment deduction. Possible termination.</td>
      </tr>
      <tr>
        <td>Cross-Posting Published Article on Another Platform</td>
        <td>Formal legal notice. Article removal. Permanent account termination.</td>
      </tr>
      <tr>
        <td>Factual Inaccuracies / Unverified Claims</td>
        <td>Article returned for correction. Partial payment deduction if editorial correction required.</td>
      </tr>
      <tr>
        <td>Failure to Update Article Within Required Timeline</td>
        <td>Warning + potential payment deduction. Repeated failure = article reassigned + termination.</td>
      </tr>
      <tr>
        <td>Unauthorized External/Affiliate Links</td>
        <td>Links removed without notice. Repeated violation = account suspension.</td>
      </tr>
      <tr>
        <td>Uploading PDFs from Unauthorized Sources</td>
        <td>File removed immediately. Warning issued.</td>
      </tr>
      <tr>
        <td>SEO Non-Compliance (after revision)</td>
        <td>Article rejected. Payment withheld for that article.</td>
      </tr>
      <tr>
        <td>Conflict of Interest Not Disclosed</td>
        <td>Article removed. Account review. Possible termination.</td>
      </tr>
    </table>

    <p><?php echo $site_name; ?> reserves the right to pursue legal remedies under applicable Indian law — including the Information Technology Act, 2000, the Copyright Act, 1957, and other applicable statutes — in cases of serious policy violations that cause reputational, financial, or legal harm to the platform.</p>

    <!-- SECTION 16 -->
    <h2 id="faq">16. Frequently Asked Questions (FAQ)</h2>

    <div class="faq-item">
      <div class="faq-q">Q1. What is the Article Writing Policy for <?php echo $site_name; ?> and who does it apply to?</div>
      <p>This Article Writing Policy applies to all contributing authors, freelance writers, and content partners who submit articles to <?php echo $site_name; ?>. It covers every aspect of content creation including SEO requirements, AI usage rules, update obligations, payment terms, internal linking standards, and content rights. All contributors must read and comply with this Policy in full before submitting any article.</p>
    </div>

    <div class="faq-item">
      <div class="faq-q">Q2. Kya main AI tools use karke article likh sakta hoon CareerDiksha ke liye? (Can I use AI tools to write articles for CareerDiksha?)</div>
      <p>Nahi, <?php echo $site_name; ?> purely AI-generated articles accept nahi karta. AI tools ko sirf ek assistive tool ke roop mein use kar sakte ho — jaise grammar check, outline banana, ya ideas brainstorm karna. Lekin article ka core research, factual verification, aur majority writing aapki khud ki honi chahiye. AI-generated content ko properly humanize, fact-check, aur rewrite karna mandatory hai.</p>
    </div>

    <div class="faq-item">
      <div class="faq-q">Q3. When will I receive my payment for articles I've written?</div>
      <p>All author payments are processed at the <strong>end of each calendar month</strong> — not per article. You will receive a consolidated payment for all articles successfully published during that month on or before the last working day. Payments are made via bank transfer (NEFT/IMPS). Advance or per-article payments are not offered.</p>
    </div>

    <div class="faq-item">
      <div class="faq-q">Q4. What is the 1-year update obligation and does it apply to all articles?</div>
      <p>Yes. Every article you publish on <?php echo $site_name; ?> comes with a <strong>mandatory one-year update commitment</strong> from the publication date. You are responsible for monitoring official sources and updating your article within 48 hours of any relevant official announcement — such as vacancy changes, exam date revisions, admit card releases, or result declarations. Failure to update on time may result in payment deductions.</p>
    </div>

    <div class="faq-item">
      <div class="faq-q">Q5. Can I publish my <?php echo $site_name; ?> article on another website or my own blog?</div>
      <p>No. Once your article is published on <?php echo $site_name; ?>, you are <strong>strictly prohibited</strong> from republishing, reposting, or reproducing the article content on any other platform — including your personal blog, Medium, LinkedIn articles, Quora, Telegram channels, or any other site. You may share a <em>link</em> to your published article. Reproducing the content itself violates this Policy and may result in legal action.</p>
    </div>

    <div class="faq-item">
      <div class="faq-q">Q6. What types of external links are allowed in my article?</div>
      <p>Only <strong>official government and organizational links</strong> are permitted as external links — for example, links to official recruitment boards (.gov.in, .nic.in), official PDF notifications, or official ministry websites. All external links must be verified as live and crawlable before submission. Affiliate links, third-party PDF hosts, links to competitor platforms, and links to pages that return 404 errors are strictly prohibited.</p>
    </div>

    <!-- SECTION 17 -->
    <h2 id="contact">17. Contact &amp; Editorial Team</h2>
    <ol>
      <li>For any questions, clarifications, or concerns regarding this Article Writing Policy, please contact our Editorial Team at <a href="mailto:<?php echo $contact_email; ?>" class="c7"><?php echo $contact_email; ?></a>. We aim to respond to all author queries within 2 business days.</li>
      <li>For payment-related queries, please email with the subject line: <strong>"Payment Query – [Your Name] – [Month Year]"</strong> to help us resolve your concern quickly.</li>
      <li>For article update submissions or notifications of official changes, use the designated Author Portal or email the editorial team with the subject line: <strong>"Article Update – [Article Title]"</strong>.</li>
      <li>Disputes, grievances, or formal complaints may be directed to our Grievance Officer:
        <br><strong>Name:</strong> Vikash Dhaker
        <br><strong>Email:</strong> <a href="mailto:<?php echo $contact_email; ?>" class="c7"><?php echo $contact_email; ?></a>
        <br><strong>Phone:</strong> <?php echo $phone_number; ?>
      </li>
    </ol>

    <hr class="divider">

    <p>We encourage you to also review our other legal and editorial policies:</p>
    <p>
      <a href="<?php echo $site_url; ?>/editorial-policy" class="c7">Editorial Policy</a> &nbsp;|&nbsp;
      <a href="<?php echo $site_url; ?>/terms-of-service" class="c7">Terms of Service</a> &nbsp;|&nbsp;
      <a href="<?php echo $site_url; ?>/privacy-policy" class="c7">Privacy Policy</a> &nbsp;|&nbsp;
      <a href="<?php echo $site_url; ?>/cookie-policy" class="c7">Cookie Policy</a> &nbsp;|&nbsp;
      <a href="<?php echo $site_url; ?>/disclaimer" class="c7">Disclaimer</a>
    </p>

  </div>
  </main>
</body>
</html>
