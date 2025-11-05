```markdown
# WordPress Vehicle Inventory XML Feed Exporter

A lightweight, automated WordPress plugin that **generates a full vehicle inventory XML feed** from your site and **uploads it via FTP**

---

## Exactly 👍 — How It Works (Step-by-Step)

Once activated, the plugin runs **once a day via WP-Cron**, or **on-demand** when you click **“Generate & Upload Now”** in the admin panel.

Here’s the full flow:  
**Database → Parse → Build XML → Save Locally → Upload via FTP**

---

## 🧠 Overview

1. **Reads** all published posts (or custom post types) from your WordPress database  
2. **Extracts** key vehicle data: VIN, Make, Model, Year, Price, Images, etc.  
3. **Builds** one clean XML file with all vehicles  
4. **Saves** it locally in `/wp-content/uploads/`  
5. **Connects** to your FTP server (using credentials you set)  
6. **Uploads** the XML file automatically  

---

## ⚙️ Detailed Step-by-Step Breakdown

### 1️⃣ Query the Database

```php
$posts = get_posts([
    'post_type'      => ['listing', 'post', 'page'],
    'post_status'    => 'publish',
    'numberposts'    => -1,
]);
```

- Uses WordPress’s native `get_posts()` — pulls directly from the `wp_posts` table  
- Easy to customize: just change `'post_type'` to match your setup (`stm_listings`, `inventory`, `cars`, etc.)  
- Each `$post` contains standard fields: `ID`, `post_title`, `post_content`, etc.

---

### 2️⃣ Parse Each Post (Extract Vehicle Data)

Smart helper functions scan content and titles for vehicle details.

#### VIN
```php
function cgfe_extract_vin($text) {
    preg_match('/\b([A-HJ-NPR-Z0-9]{11,17})\b/i', $text, $m);
    return strtoupper($m[1] ?? '');
}
```
→ Finds valid VINs (11–17 chars, no I/O/Q) anywhere in the post

#### Price
```php
preg_match('/\$\s*([0-9\.,]+)/', $text, $m);
```
→ Matches `$19,995`, `$25000`, etc.

#### Year / Make / Model
```php
list($make, $model, $trim) = cgfe_parse_title_for_make_model_trim($post->post_title);
```
→ From `"2017 Toyota Camry SE"` → `Year: 2017`, `Make: Toyota`, `Model: Camry`, `Trim: SE`

#### Images
```php
$images = cgfe_extract_image_urls_from_content($post->post_content);
$att    = get_attached_media('image', $post->ID);
```
→ Grabs `<img>` tags **and** attached media from the Media Library

---

### 3️⃣ Build the XML Document

Uses PHP’s `SimpleXMLElement` to create structured output:

```php
$vehicle = $xml->addChild('vehicle');
$vehicle->addChild('VIN', $vin);
$vehicle->addChild('Make', $make);
$vehicle->addChild('Model', $model);
// ... and more
```

#### Example Output:
```xml
<inventory>
  <vehicle>
    <VIN>1HGCM82633A123456</VIN>
    <Make>Honda</Make>
    <Model>Accord</Model>
    <Year>2019</Year>
    <Trim>EX-L</Trim>
    <Price>15995</Price>
    <Mileage>45200 mi</Mileage>
    <ImageURLs>
      <Image>https://yoursite.com/wp-content/uploads/1.jpg</Image>
      <Image>https://yoursite.com/wp-content/uploads/2.jpg</Image>
    </ImageURLs>
    <ExteriorColor>Blue</ExteriorColor>
    <DealerComments>Well maintained, single owner.</DealerComments>
    <StockNumber>12345</StockNumber>
    <TransmissionType>Automatic</TransmissionType>
    <InstalledOptions>Leather Seats; Sunroof</InstalledOptions>
    <DealerID>DEALER123</DealerID>
    <DealerName>Quick Auto Sales</DealerName>
  </vehicle>
</inventory>
```

Saved as:  
`/wp-content/uploads/cgfe_feed_20251105_143022.xml`

---

### 4️⃣ Upload via FTP

Pulls your saved settings:
```php
$ftp_host = get_option('cgfe_ftp_host');
$ftp_user = get_option('cgfe_ftp_user');
$ftp_pass = get_option('cgfe_ftp_pass');
$ftp_path = get_option('cgfe_ftp_path', '/');
```

Then uploads:
```php
$conn = ftp_connect($ftp_host, 21, 10);
ftp_login($conn, $ftp_user, $ftp_pass);
ftp_pasv($conn, true);
ftp_put($conn, $ftp_path . basename($localfile), $localfile, FTP_BINARY);
ftp_close($conn);
```

Your feed is now live on the remote server!

---

### 5️⃣ Daily Automation (WP-Cron)

On activation:
```php
register_activation_hook(__FILE__, 'cgfe_activate');
wp_schedule_event(time(), 'daily', 'cgfe_daily_event');
```

Every day:
```php
add_action('cgfe_daily_event', 'cgfe_generate_and_upload_feed');
```

Runs the full process automatically — no manual work needed.

---

### 6️⃣ Manual Trigger (Admin Panel)

- Adds **“Car Feed Export”** menu in WP Admin  
- Click **“Generate & Upload Now”** to run instantly  
- Shows success/failure logs right in the dashboard

---

## 🧩 Summary Flow

| Step | Action | Tool/Function |
|------|------|----------------|
| 1 | Get all posts | `get_posts()` |
| 2 | Extract VIN, Price, etc. | Regex + helpers |
| 3 | Build XML | `SimpleXMLElement` |
| 4 | Save file | `$xml->asXML()` |
| 5 | Upload | `ftp_connect()`, `ftp_put()` |
| 6 | Auto-run daily | `wp_schedule_event()` |


---

## Installation

1. Download & upload to `/wp-content/plugins/`
2. Activate in WordPress
3. Go to **Car Feed Export** in admin menu
4. Enter your **FTP credentials**
5. Click **Save & Test Connection**
6. Done! Runs daily + on-demand.

---

Built with love for dealerships who hate manual exports.  
No bloat. No fluff. Just works.

---
