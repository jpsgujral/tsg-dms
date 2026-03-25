<?php
/**
 * Daily Activity Report  Cron Script
 * 
 * Sends a summary email at end of day covering:
 * 1. New despatches created today
 * 2. Deliveries completed today
 * 3. Pending / In-Transit summary
 * 4. Freight & payment summary
 *
 * SETUP:
 * 1. Upload to: /despatch_mgmt/cron/daily_report.php
 * 2. Add cron job in cPanel:
 *    59 23 * * * /usr/local/bin/php /home/tsgimpex/public_html/despatch_mgmt/cron/daily_report.php >> /home/tsgimpex/logs/daily_report.log 2>&1
 * 3. Configure recipient emails below
 */

/*  RECIPIENT EMAILS  */
/* Add/remove emails here. The report will be sent to ALL of these. */
$REPORT_RECIPIENTS = [
    'support@tsgimpex.com',
    'tsgaccounts@tsgimpex.com',
];

/*  Bootstrap  */
define('CRON_MODE', true);
$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';

$db = getDB();

/*  Validate recipients  */
if (empty($REPORT_RECIPIENTS)) {
    echo date('Y-m-d H:i:s') . " ERROR: No recipient emails configured in daily_report.php\n";
    exit(1);
}

/*  Load SMTP settings  */
$smtp = $db->query("SELECT smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure, smtp_from_name, company_name
    FROM company_settings LIMIT 1")->fetch_assoc();

if (empty($smtp['smtp_host']) || empty($smtp['smtp_user'])) {
    echo date('Y-m-d H:i:s') . " ERROR: SMTP not configured in Company Settings\n";
    exit(1);
}

$today     = date('Y-m-d');
$today_fmt = date('d M Y');
$company   = $smtp['company_name'] ?: 'DMS';

/* 
   SECTION 1: New Despatches Created Today
    */
$new_despatches = $db->query("
    SELECT d.challan_no, d.despatch_date, d.consignee_name, d.consignee_city,
           d.total_weight, d.freight_amount, d.total_amount, d.status,
           t.transporter_name, d.vehicle_no
    FROM despatch_orders d
    LEFT JOIN transporters t ON d.transporter_id = t.id
    WHERE DATE(d.created_at) = '$today'
    ORDER BY d.id DESC
")->fetch_all(MYSQLI_ASSOC);

/* 
   SECTION 2: Deliveries Completed Today
    */
$deliveries = $db->query("
    SELECT d.challan_no, d.despatch_date, d.consignee_name, d.consignee_city,
           d.total_weight, d.freight_amount, d.total_amount,
           t.transporter_name, d.vehicle_no
    FROM despatch_orders d
    LEFT JOIN transporters t ON d.transporter_id = t.id
    WHERE d.status = 'Delivered' AND DATE(d.updated_at) = '$today'
    ORDER BY d.id DESC
")->fetch_all(MYSQLI_ASSOC);

/* 
   SECTION 3: Pending / In-Transit Summary
    */
$pending = $db->query("
    SELECT d.status, COUNT(*) AS cnt,
           SUM(d.total_weight) AS total_wt,
           SUM(d.total_amount) AS total_amt,
           SUM(d.freight_amount) AS total_frt
    FROM despatch_orders d
    WHERE d.status IN ('Draft','Despatched','In Transit')
    GROUP BY d.status
    ORDER BY FIELD(d.status,'Draft','Despatched','In Transit')
")->fetch_all(MYSQLI_ASSOC);

/* 
   SECTION 4: Freight & Payment Summary (Today)
    */
$freight_today = $db->query("
    SELECT SUM(freight_amount) AS total_freight,
           SUM(vendor_freight_amount) AS total_vendor_freight,
           SUM(total_amount) AS total_despatch_value,
           SUM(total_weight) AS total_weight,
           COUNT(*) AS order_count
    FROM despatch_orders
    WHERE DATE(created_at) = '$today'
")->fetch_assoc();

$payments_today = $db->query("
    SELECT SUM(amount) AS total_paid, COUNT(*) AS payment_count
    FROM transporter_payments
    WHERE DATE(payment_date) = '$today' AND status = 'Paid'
")->fetch_assoc();

/* 
   BUILD HTML EMAIL
    */
$green = '#1a5632';
$green_light = '#27ae60';

$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;font-size:14px;color:#333;background:#f5f5f5;margin:0;padding:0">
<div style="max-width:700px;margin:20px auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08)">';

/*  Header  */
$html .= '<div style="background:linear-gradient(135deg,'.$green.','.$green_light.');color:#fff;padding:22px 28px">
    <h2 style="margin:0;font-size:20px">Daily Activity Report</h2>
    <p style="margin:5px 0 0;opacity:0.85;font-size:13px">'.$company.'  '.$today_fmt.'</p>
</div>';

$html .= '<div style="padding:20px 28px">';

/*  Section 1: New Despatches  */
$html .= '<h3 style="color:'.$green.';border-bottom:2px solid '.$green.';padding-bottom:6px;margin-top:0">
    New Despatches Created Today ('.count($new_despatches).')</h3>';

if (empty($new_despatches)) {
    $html .= '<p style="color:#888;font-style:italic">No new despatches created today.</p>';
} else {
    $html .= '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:16px">
        <tr style="background:'.$green.';color:#fff">
            <th style="padding:6px 8px;text-align:left">Challan No</th>
            <th style="padding:6px 8px">Consignee</th>
            <th style="padding:6px 8px">Transporter</th>
            <th style="padding:6px 8px">Vehicle</th>
            <th style="padding:6px 8px;text-align:right">Weight</th>
            <th style="padding:6px 8px;text-align:right">Amount</th>
            <th style="padding:6px 8px">Status</th>
        </tr>';
    foreach ($new_despatches as $i => $r) {
        $bg = $i % 2 ? '#f9f9f9' : '#fff';
        $html .= '<tr style="background:'.$bg.'">
            <td style="padding:5px 8px;border-bottom:1px solid #eee"><strong>'.$r['challan_no'].'</strong></td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee">'.$r['consignee_name'].' <small style="color:#888">'.$r['consignee_city'].'</small></td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee">'.$r['transporter_name'].'</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee">'.$r['vehicle_no'].'</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee;text-align:right">'.number_format((float)$r['total_weight'],3).'</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee;text-align:right">'.number_format((float)$r['total_amount'],2).'</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee">'.$r['status'].'</td>
        </tr>';
    }
    $html .= '</table>';
}

/*  Section 2: Deliveries Completed  */
$html .= '<h3 style="color:'.$green.';border-bottom:2px solid '.$green.';padding-bottom:6px">
    Deliveries Completed Today ('.count($deliveries).')</h3>';

if (empty($deliveries)) {
    $html .= '<p style="color:#888;font-style:italic">No deliveries completed today.</p>';
} else {
    $del_total_wt  = array_sum(array_column($deliveries, 'total_weight'));
    $del_total_amt = array_sum(array_column($deliveries, 'total_amount'));
    $html .= '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:16px">
        <tr style="background:'.$green.';color:#fff">
            <th style="padding:6px 8px;text-align:left">Challan No</th>
            <th style="padding:6px 8px">Consignee</th>
            <th style="padding:6px 8px">Transporter</th>
            <th style="padding:6px 8px;text-align:right">Weight</th>
            <th style="padding:6px 8px;text-align:right">Amount</th>
        </tr>';
    foreach ($deliveries as $i => $r) {
        $bg = $i % 2 ? '#f9f9f9' : '#fff';
        $html .= '<tr style="background:'.$bg.'">
            <td style="padding:5px 8px;border-bottom:1px solid #eee"><strong>'.$r['challan_no'].'</strong></td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee">'.$r['consignee_name'].'</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee">'.$r['transporter_name'].'</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee;text-align:right">'.number_format((float)$r['total_weight'],3).'</td>
            <td style="padding:5px 8px;border-bottom:1px solid #eee;text-align:right">'.number_format((float)$r['total_amount'],2).'</td>
        </tr>';
    }
    $html .= '<tr style="background:#e5f5eb;font-weight:bold">
        <td colspan="3" style="padding:6px 8px;text-align:right">Total Delivered:</td>
        <td style="padding:6px 8px;text-align:right">'.number_format($del_total_wt,3).' MT</td>
        <td style="padding:6px 8px;text-align:right">'.number_format($del_total_amt,2).'</td>
    </tr></table>';
}

/*  Section 3: Pending / In-Transit  */
$html .= '<h3 style="color:'.$green.';border-bottom:2px solid '.$green.';padding-bottom:6px">
    Pending &amp; In-Transit Summary</h3>';

if (empty($pending)) {
    $html .= '<p style="color:#888;font-style:italic">No pending or in-transit orders.</p>';
} else {
    $status_colors = ['Draft'=>'#6c757d','Despatched'=>'#0d6efd','In Transit'=>'#ffc107'];
    $html .= '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px">
        <tr style="background:#f5f5f5">
            <th style="padding:8px;text-align:left;border-bottom:2px solid #ddd">Status</th>
            <th style="padding:8px;text-align:center;border-bottom:2px solid #ddd">Orders</th>
            <th style="padding:8px;text-align:right;border-bottom:2px solid #ddd">Total Weight</th>
            <th style="padding:8px;text-align:right;border-bottom:2px solid #ddd">Total Amount</th>
            <th style="padding:8px;text-align:right;border-bottom:2px solid #ddd">Freight</th>
        </tr>';
    $grand_cnt = 0; $grand_wt = 0; $grand_amt = 0; $grand_frt = 0;
    foreach ($pending as $r) {
        $sc = $status_colors[$r['status']] ?? '#333';
        $grand_cnt += $r['cnt']; $grand_wt += $r['total_wt']; $grand_amt += $r['total_amt']; $grand_frt += $r['total_frt'];
        $html .= '<tr>
            <td style="padding:6px 8px;border-bottom:1px solid #eee"><span style="background:'.$sc.';color:#fff;padding:2px 8px;border-radius:4px;font-size:11px">'.$r['status'].'</span></td>
            <td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:center;font-weight:bold">'.$r['cnt'].'</td>
            <td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:right">'.number_format((float)$r['total_wt'],3).' MT</td>
            <td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:right">'.number_format((float)$r['total_amt'],2).'</td>
            <td style="padding:6px 8px;border-bottom:1px solid #eee;text-align:right">'.number_format((float)$r['total_frt'],2).'</td>
        </tr>';
    }
    $html .= '<tr style="background:#e5f5eb;font-weight:bold">
        <td style="padding:6px 8px">Total</td>
        <td style="padding:6px 8px;text-align:center">'.$grand_cnt.'</td>
        <td style="padding:6px 8px;text-align:right">'.number_format($grand_wt,3).' MT</td>
        <td style="padding:6px 8px;text-align:right">'.number_format($grand_amt,2).'</td>
        <td style="padding:6px 8px;text-align:right">'.number_format($grand_frt,2).'</td>
    </tr></table>';
}

/*  Section 4: Freight & Payment Summary  */
$html .= '<h3 style="color:'.$green.';border-bottom:2px solid '.$green.';padding-bottom:6px">
    Freight &amp; Payment Summary (Today)</h3>';

$html .= '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px">';
$html .= '<tr><td style="padding:8px;border-bottom:1px solid #eee;width:60%">New Orders Today</td>
    <td style="padding:8px;border-bottom:1px solid #eee;text-align:right;font-weight:bold">'.(int)($freight_today['order_count']??0).'</td></tr>';
$html .= '<tr><td style="padding:8px;border-bottom:1px solid #eee">Total Despatch Weight</td>
    <td style="padding:8px;border-bottom:1px solid #eee;text-align:right;font-weight:bold">'.number_format((float)($freight_today['total_weight']??0),3).' MT</td></tr>';
$html .= '<tr><td style="padding:8px;border-bottom:1px solid #eee">Total Despatch Value</td>
    <td style="padding:8px;border-bottom:1px solid #eee;text-align:right;font-weight:bold">'.number_format((float)($freight_today['total_despatch_value']??0),2).'</td></tr>';
$html .= '<tr><td style="padding:8px;border-bottom:1px solid #eee">Transporter Freight</td>
    <td style="padding:8px;border-bottom:1px solid #eee;text-align:right;font-weight:bold">'.number_format((float)($freight_today['total_freight']??0),2).'</td></tr>';
$html .= '<tr><td style="padding:8px;border-bottom:1px solid #eee">Vendor Freight Charged</td>
    <td style="padding:8px;border-bottom:1px solid #eee;text-align:right;font-weight:bold">'.number_format((float)($freight_today['total_vendor_freight']??0),2).'</td></tr>';
$html .= '<tr style="background:#e5f5eb"><td style="padding:8px;font-weight:bold">Payments Made Today</td>
    <td style="padding:8px;text-align:right;font-weight:bold">'.number_format((float)($payments_today['total_paid']??0),2).' ('.(int)($payments_today['payment_count']??0).' payments)</td></tr>';
$html .= '</table>';

/*  Footer  */
$html .= '</div>'; // close padding div
$html .= '<div style="background:#f5f5f5;padding:14px 28px;text-align:center;font-size:11px;color:#888;border-top:1px solid #eee">
    This is an automated daily report from '.$company.' Despatch Management System.<br>
    Generated on '.date('d/m/Y').' at '.date('h:i A').'
</div>';
$html .= '</div></body></html>';

/* 
   SEND EMAIL VIA SMTP
    */

// Use PHPMailer if available, else fall back to raw SMTP
$phpmailer_path = $base_path . '/includes/phpmailer/src/PHPMailer.php';
$phpmailer_smtp = $base_path . '/includes/phpmailer/src/SMTP.php';
$phpmailer_exc  = $base_path . '/includes/phpmailer/src/Exception.php';

// Also check alternate paths
if (!file_exists($phpmailer_path)) {
    $phpmailer_path = $base_path . '/includes/phpmailer/src/PHPMailer.php';
    $phpmailer_smtp = $base_path . '/includes/phpmailer/src/SMTP.php';
    $phpmailer_exc  = $base_path . '/includes/phpmailer/src/Exception.php';
}
if (!file_exists($phpmailer_path)) {
    $phpmailer_path = $base_path . '/includes/PHPMailer/PHPMailer.php';
    $phpmailer_smtp = $base_path . '/includes/PHPMailer/SMTP.php';
    $phpmailer_exc  = $base_path . '/includes/PHPMailer/Exception.php';
}

$subject = "Daily Activity Report  $company  $today_fmt";

if (file_exists($phpmailer_path)) {
    /*  PHPMailer  */
    require_once $phpmailer_exc;
    require_once $phpmailer_path;
    require_once $phpmailer_smtp;

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $smtp['smtp_host'];
        $port   = (int)($smtp['smtp_port'] ?: 587);
        $secure = strtolower(trim($smtp['smtp_secure'] ?: ''));
        if ($port === 465 || $secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMIME;
            $mail->Port       = 465;
        } else {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $port;
        }
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp['smtp_user'];
        $mail->Password   = trim($smtp['smtp_pass']);
        // Bypass SSL cert verification — required on cPanel shared hosting
        // where the host intercepts SMTP and presents its own certificate
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ];
        $mail->setFrom($smtp['smtp_user'], $smtp['smtp_from_name'] ?: $company);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $html;
        $mail->AltBody = strip_tags(str_replace(['<br>','<br/>','</tr>','</td>'], ["\n","\n","\n","\t"], $html));

        foreach ($REPORT_RECIPIENTS as $email) {
            $mail->addAddress(trim($email));
        }

        $mail->send();
        echo date('Y-m-d H:i:s') . " OK: Daily report sent to " . implode(', ', $REPORT_RECIPIENTS) . "\n";
    } catch (Exception $e) {
        echo date('Y-m-d H:i:s') . " ERROR: " . $mail->ErrorInfo . "\n";
        exit(1);
    }
} else {
    /*  Fallback: PHP mail() with headers  */
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . ($smtp['smtp_from_name'] ?: $company) . " <" . $smtp['smtp_user'] . ">\r\n";

    $to = implode(',', $REPORT_RECIPIENTS);
    if (mail($to, $subject, $html, $headers)) {
        echo date('Y-m-d H:i:s') . " OK: Daily report sent via mail() to $to\n";
    } else {
        echo date('Y-m-d H:i:s') . " ERROR: mail() failed\n";
        exit(1);
    }
}
