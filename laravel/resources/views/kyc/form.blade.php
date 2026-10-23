<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Guest Registration | Haven Suites</title>
    <style>
        :root{font-family:Arial,Helvetica,sans-serif;color:#1d2b3c;background:#f3f6fa}
        *{box-sizing:border-box}body{margin:0}header{background:#102b4e;color:white;padding:24px 16px;text-align:center}
        header h1{margin:0;font-size:26px;letter-spacing:1px}header p{margin:7px 0 0;color:#d8e3ef;font-size:14px}
        main{max-width:660px;margin:28px auto 60px;padding:0 16px}.card{background:white;border:1px solid #e1e7ee;border-radius:12px;padding:28px;box-shadow:0 6px 24px #102b4e0b}
        h2{margin:0 0 8px;color:#102b4e;font-size:23px}.intro{color:#596879;line-height:1.55;margin:0 0 24px}
        .booking{background:#f4f7fb;border-left:4px solid #ef8a24;border-radius:5px;padding:13px 16px;margin-bottom:24px;color:#243c59}
        .booking strong{display:block;margin-bottom:5px}.field{margin-bottom:19px}label{display:block;font-weight:700;font-size:14px;margin-bottom:7px}
        input,select{display:block;width:100%;font:inherit;padding:12px;border:1px solid #cbd5e1;border-radius:7px;background:white;color:#1d2b3c}
        input:focus,select:focus{outline:2px solid #e6a35e;border-color:#d47a1b}.required{color:#c34532}
        small{display:block;color:#64748b;line-height:1.5;margin-top:6px}.error{background:#fff0ed;border:1px solid #f3b5a9;color:#842c20;border-radius:7px;padding:12px;margin-bottom:20px}
        .error ul{margin:8px 0 0;padding-left:20px}.consent{display:flex;gap:10px;align-items:flex-start;margin:20px 0;font-size:14px;line-height:1.5}
        .consent input{width:auto;margin:3px 0 0;flex-shrink:0}.submit{background:#ef8a24;border:0;border-radius:7px;color:#172c46;font-weight:700;font-size:16px;padding:14px 20px;width:100%;cursor:pointer}
        .submit:hover{background:#e27b15}.foot{text-align:center;color:#738093;font-size:12px;margin-top:20px}
        @media(max-width:500px){.card{padding:20px}main{margin-top:18px}}
    </style>
</head>
<body>
<header><h1>HAVEN SUITES</h1><p>BNBs AND STR HOMES</p></header>
<main>
    <div class="card">
        <h2>Guest KYC Registration</h2>
        <p class="intro">Please provide your official contact and identification details for your reservation. These details will also be used when preparing your invoice.</p>

        <div class="booking">
            <strong>Reservation #<?= esc((string) ($reservation['id'] ?? '')) ?></strong>
            <?php if (! empty($reservation['check_in']) && ! empty($reservation['check_out'])): ?>
                Check-in: <?= esc((string) $reservation['check_in']) ?> &nbsp; | &nbsp; Check-out: <?= esc((string) $reservation['check_out']) ?>
            <?php endif; ?>
        </div>

        <?php if (! empty($validationErrors)): ?>
            <div class="error" role="alert">
                <strong>Please correct the following:</strong>
                <ul>
                    <?php foreach ($validationErrors as $error): ?>
                        <li><?= esc((string) $error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (haven_can_access(esc(site_url('kyc/' . $token . '/submit'), 'attr'), 'POST')): ?><form method="post" action="<?= esc(site_url('kyc/' . $token . '/submit'), 'attr') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="field">
                <label for="full_name">Full name as shown on your ID <span class="required">*</span></label>
                <input id="full_name" name="full_name" type="text" maxlength="180" autocomplete="name" required value="<?= esc((string) old('full_name', ''), 'attr') ?>">
            </div>
            <div class="field">
                <label for="email">Email address</label>
                <input id="email" name="email" type="email" maxlength="180" autocomplete="email" value="<?= esc((string) old('email', ''), 'attr') ?>">
            </div>
            <div class="field">
                <label for="primary_phone">Primary phone number <span class="required">*</span></label>
                <input id="primary_phone" name="primary_phone" type="tel" maxlength="40" autocomplete="tel" placeholder="e.g. +254 7XX XXX XXX" required value="<?= esc((string) old('primary_phone', ''), 'attr') ?>">
            </div>
            <div class="field">
                <label for="alternative_contact">Alternative contact / phone</label>
                <input id="alternative_contact" name="alternative_contact" type="text" maxlength="180" value="<?= esc((string) old('alternative_contact', ''), 'attr') ?>">
            </div>
            <div class="field">
                <label for="document_type">Identification document type <span class="required">*</span></label>
                <select id="document_type" name="document_type" required>
                    <option value="">Select document type</option>
                    <?php foreach (['national_id' => 'National ID', 'passport' => 'Passport', 'alien_id' => 'Alien ID', 'driving_licence' => 'Driving licence'] as $value => $label): ?>
                        <option value="<?= esc($value, 'attr') ?>" <?= old('document_type') === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="identity_document">Upload identification document <span class="required">*</span></label>
                <input id="identity_document" name="identity_document" type="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" required>
                <small>JPG, PNG or PDF only. Maximum file size: 5 MB. Please upload a clear, readable copy.</small>
            </div>
            <label class="consent" for="consent">
                <input type="checkbox" id="consent" required>
                <span>I confirm that the information is accurate and understand it will be used for guest identification, reservation administration and invoicing.</span>
            </label>
            <button class="submit" type="submit">Submit Guest Registration</button>
        </form><?php endif; ?>
    </div>
    <p class="foot">Haven Suites BNB &bull; Guest information is handled for reservation and identification purposes.</p>
</main>
</body>
</html>
