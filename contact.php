<?php
declare(strict_types=1);
require __DIR__ . '/includes/layout.php';

$submitted = false;
$errors = [];
$values = ['name' => '', 'email' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['name']    = trim((string) ($_POST['name'] ?? ''));
    $values['email']   = trim((string) ($_POST['email'] ?? ''));
    $values['message'] = trim((string) ($_POST['message'] ?? ''));

    if ($values['name'] === '')                           $errors['name']    = 'Please enter your name.';
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Please enter a valid email address.';
    if (strlen($values['message']) < 10)                  $errors['message'] = 'Message is too short — please add a bit more detail.';

    if (!$errors) {
        $line = sprintf(
            "[%s] %s <%s>: %s\n",
            date('Y-m-d H:i:s'),
            str_replace(["\n", "\r"], ' ', $values['name']),
            str_replace(["\n", "\r"], ' ', $values['email']),
            str_replace(["\n", "\r"], ' ', $values['message'])
        );
        $logDir = __DIR__ . '/storage';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        @file_put_contents($logDir . '/contact.log', $line, FILE_APPEND | LOCK_EX);
        $submitted = true;
        $values = ['name' => '', 'email' => '', 'message' => ''];
    }
}

layout_head('Contact · imeihub', 'Get in touch with the imeihub team. We respond to all inquiries within two business days.');
?>
    <section class="page-hero">
        <div class="container">
            <h1>Contact us</h1>
            <p class="lede">
                Bug report, feature idea, or partnership inquiry?
                Drop us a note and we will respond within two business days.
            </p>
        </div>
    </section>

    <section class="prose">
        <div class="container container--prose">
            <?php if ($submitted): ?>
                <div class="alert alert-success">
                    <strong>Thanks!</strong> Your message has been received. We will reply by email.
                </div>
            <?php endif; ?>

            <form class="contact-form" method="post" action="/contact.php" novalidate>
                <label>
                    <span>Your name</span>
                    <input type="text" name="name" required value="<?= htmlspecialchars($values['name'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php if (isset($errors['name'])): ?>
                        <em class="field-error"><?= htmlspecialchars($errors['name'], ENT_QUOTES, 'UTF-8') ?></em>
                    <?php endif; ?>
                </label>

                <label>
                    <span>Email address</span>
                    <input type="email" name="email" required value="<?= htmlspecialchars($values['email'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php if (isset($errors['email'])): ?>
                        <em class="field-error"><?= htmlspecialchars($errors['email'], ENT_QUOTES, 'UTF-8') ?></em>
                    <?php endif; ?>
                </label>

                <label>
                    <span>Message</span>
                    <textarea name="message" rows="6" required><?= htmlspecialchars($values['message'], ENT_QUOTES, 'UTF-8') ?></textarea>
                    <?php if (isset($errors['message'])): ?>
                        <em class="field-error"><?= htmlspecialchars($errors['message'], ENT_QUOTES, 'UTF-8') ?></em>
                    <?php endif; ?>
                </label>

                <button type="submit">Send message</button>
            </form>
        </div>
    </section>
<?php layout_foot(); ?>
