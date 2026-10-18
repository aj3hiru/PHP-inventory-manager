<?php
require_once __DIR__ . '/includes/config.php';
require_once INCLUDES_PATH . '/minify.php';
require_once INCLUDES_PATH . '/functions.php';



$cache_key = 'all_states_list_sorted';
$states = false;

if (function_exists('apcu_fetch') && apcu_enabled()) {
    $states = apcu_fetch($cache_key, $success);
    if (!$success) $states = false;
}

if ($states === false) {
    $stmt = $pdo->query("SELECT * FROM states ORDER BY views DESC, state_name ASC");
    $states = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (function_exists('apcu_store') && apcu_enabled()) {
        apcu_store($cache_key, $states, 86400);
    }
}



$colors = [
    '#0062cc', '#520dc2', '#5a359c', '#b83274',
    '#b02a37', '#ca650f', '#146c43', '#1aa17f',
    '#0aa3c2', '#0a58ca', '#ab296a', '#117a8b',
    '#233240', '#6f378a', '#992d23', '#a84300',
    '#12806b', '#1f8e4f', '#21679a', '#c27d0e'
];


$seo = [
    'title'       => 'State Wise Government Jobs 2026 - Find Sarkari Naukri by State | ' . SITE_NAME,
    'description' => 'Find latest government jobs 2026 by state. Browse Sarkari Naukri notifications for Rajasthan, UP, Bihar, Maharashtra, Delhi and all other states in India.',
    'image'       => SEO_DEFAULT_IMAGE,
    'type'        => SEO_DEFAULT_TYPE,
    'robots'      => SEO_DEFAULT_ROBOTS,
];



$faqs = [

    [
        'question'    => 'How to find government jobs in my state?',
        'answer_html' => 'Simply click on your state name from the list above. You will be directed to a dedicated page listing all active recruitments, exam notifications, and upcoming vacancies for that specific state.',
    ],

    [
        'question'    => 'Does this list cover all states in India?',
        'answer_html' => 'Yes, we provide government job updates for all 28 states and 8 Union Territories of India, including Rajasthan, Uttar Pradesh, Bihar, Maharashtra, Delhi, and others.',
    ],

    [
        'question'    => 'Are these jobs updated daily?',
        'answer_html' => 'Yes, our team monitors official state public service commission websites (like RPSC, UPPSC, BPSC) and other departmental sites daily to provide the latest free job alerts.',
    ],

    [
        'question'    => 'Can 10th and 12th pass students apply for state jobs?',
        'answer_html' => 'Yes, many state government vacancies like Police Constable, Clerk (LDC), Forest Guard, and Group D posts are specifically released for 10th and 12th pass candidates.',
    ],

    [
        'question'    => 'Is registration required to view these jobs?',
        'answer_html' => 'No, you do not need to register or pay any fee to view these job alerts. Our platform is completely free for all students looking for Sarkari Naukri updates.',
    ],

];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($seo['title'], ENT_QUOTES, 'UTF-8') ?></title>
    
    <?php require_once ROOT_PATH . '/components/head_script.php'; ?>

    <style>
        .states-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 15px;
            margin: 15px 0;
        }
        .state-card {
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 8px 10px;
            border-radius: 6px;
            text-decoration: none;
            color: #fff;
            min-height: 50px;
            transition: transform 0.2s, filter 0.2s;
            font-weight: 700;
            line-height: 1.3;
        }
        .state-card h2 {
            font-size: 1rem;
            margin: 0;
            color: #fff;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }
        @media (max-width: 600px) {
            .states-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>

<!-- BREADCRUMBS SCHEMA -->    
    <script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    {
      "@type": "ListItem",
      "position": 1,
      "name": "Home",
      "item": "<?= htmlspecialchars(SITE_URL) ?>"
    },
    {
      "@type": "ListItem",
      "position": 2,
      "name": "State Wise Jobs"
    }
  ]
}
</script>


<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "CollectionPage",
  "name": "<?= htmlspecialchars($seo['title'], ENT_QUOTES, 'UTF-8') ?>",
  "description": "<?= htmlspecialchars($seo['description'], ENT_QUOTES, 'UTF-8') ?>",
  "url": "<?= htmlspecialchars(SITE_URL) ?>/state-jobs",
  "hasPart": [
    <?php foreach ($states as $index => $state): ?>
    {
      "@type": "WebPage",
      "name": "<?= htmlspecialchars($state['state_name']) ?> Government Jobs",
      "url": "<?= htmlspecialchars(SITE_URL) ?>/state-jobs/<?= htmlspecialchars($state['slug']) ?>",
      "description": "<?= htmlspecialchars(strip_tags($state['description'])) ?>"
    }<?= $index < count($states) - 1 ? ',' : '' ?>
    <?php endforeach; ?>
  ]
}
</script>

</head>
<body>
	
    <?php require_once ROOT_PATH . '/components/header.php'; ?>
    
    <main>
        <nav class="breadcrumbs" aria-label="Breadcrumb">
            <ol>
                <li><a href="/">Home</a></li>
                <li aria-current="page">State Wise Jobs</li>
            </ol>
        </nav>

        <div class="container">
             <h1 class="page-title">State Wise Government Jobs 2026 - Find Sarkari Naukri by State</h1>
                <p>Explore latest Sarkari Naukri notifications, recruitment exams, and vacancy updates across all states and union territories of India.</p>
            
            <?php if (empty($states)): ?>
                <p>No states found.</p>
            <?php else: ?>
                <div class="states-grid">
                    <?php foreach ($states as $index => $state): 
                        $bg = $colors[$index % count($colors)];
                    ?>
                        <a href="/state-jobs/<?= htmlspecialchars($state['slug']) ?>" 
                           class="state-card" 
                           style="background-color: <?= $bg ?>;">
                            <h2><?= htmlspecialchars($state['state_name']) ?></h2>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

<?php require_once ROOT_PATH . '/components/faq.php'; ?>
    
    </main>
    
    <?php require_once ROOT_PATH . '/components/footer.php'; ?>
    
</body>
</html>
<?php ob_end_flush(); ?>