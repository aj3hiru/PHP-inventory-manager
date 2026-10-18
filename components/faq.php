<!-- FAQ SECTION -->
<section class="faq" aria-labelledby="faq-heading">

    <div class="faq-header">
        <h2 id="faq-heading">
            Frequently Asked Questions (FAQs)
        </h2>
    </div>

    <?php foreach ($faqs as $faq): ?>

<details class="faq-item">
    
    <summary>
        <?= htmlspecialchars($faq['question']) ?>
    </summary>

    <article class="faq-content">
        <?= $faq['answer_html'] ?>
    </article>

</details>

<?php endforeach; ?>
</section>

<!-- FAQ SCHEMA MARKUP SECTION -->
<script type="application/ld+json">
<?= json_encode([

    '@context' => 'https://schema.org',

    '@type' => 'FAQPage',

    'mainEntity' => array_map(

        static fn ($faq) => [

            '@type' => 'Question',

            'name' => strip_tags($faq['question']),

            'acceptedAnswer' => [

                '@type' => 'Answer',

                'text' => trim(
                    preg_replace(
                        '/\s+/',
                        ' ',
                        strip_tags($faq['answer_html'])
                    )
                ),

            ],

        ],

        $faqs

    ),

], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
</script>