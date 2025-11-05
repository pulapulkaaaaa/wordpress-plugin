## 🧠 Overview

Once activated, the plugin **automatically runs once a day** (via WP-Cron) or whenever you click “Generate & Upload Now” in the admin panel.
It does this:

1. **Reads all posts from your WordPress database** (`wp_posts` table).
2. **Extracts required vehicle data fields** (VIN, Make, Model, Year, Price, etc.).
3. **Builds one XML file** containing all vehicles.
4. **Saves the XML file locally** in the WordPress uploads folder.
5. **Connects to your FTP server** using credentials you set in the plugin settings.
6. **Uploads the XML file** to your FTP directory.

---

## ⚙️ Step-by-Step How It Works

### 1️⃣ Query the Database

```php
$posts = get_posts([
  'post_type' => ['listing','post','page'],
  'post_status' => 'publish',
  'numberposts' => -1,
]);
```

* This uses WordPress’s built-in `get_posts()` function, which reads directly from the **`wp_posts` table** in your database.
* You can change `'post_type'` to match your actual vehicle listings (for example `cars`, `inventory`, `stm_listings`, etc.).
* Each returned `$post` object contains the same data as a DB row: `ID`, `post_title`, `post_content`, etc.

---

### 2️⃣ Parse Each Post (Extract Vehicle Data)

For every post, the plugin runs through small helper functions to look for required information.

Examples:

#### VIN

```php
function cgfe_extract_vin($text) {
  preg_match('/\b([A-HJ-NPR-Z0-9]{11,17})\b/i', $text, $m);
  return strtoupper($m[1] ?? '');
}
```

→ Looks inside the post content for a valid VIN pattern (17 characters, alphanumeric).

#### Price

```php
preg_match('/\$\s*([0-9\.,]+)/', $text, $m);
```

→ Finds a price written like “$19,995”.

#### Year / Make / Model

```php
list($make,$model,$trim) = cgfe_parse_title_for_make_model_trim($post->post_title);
```

→ Splits the title (e.g. “2017 Toyota Camry SE”) into Year, Make, Model, Trim.

#### Images

```php
$images = cgfe_extract_image_urls_from_content($post->post_content);
$att = get_attached_media('image', $post->ID);
```

→ Finds all `<img src="...">` tags inside the post HTML and also attached media in the WordPress library.

---

### 3️⃣ Build the XML Document

A root `<inventory>` element is created with PHP’s `SimpleXMLElement`.
For each post, a `<vehicle>` node is added and filled with child tags:

```php
$vehicle = $xml->addChild('vehicle');
$vehicle->addChild('VIN', $vin);
$vehicle->addChild('Make', $make);
$vehicle->addChild('Model', $model);
...
```

The final XML looks like:

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
      <Image>https://wordpress.com/wp-content/uploads/1.jpg</Image>
      <Image>https://wordpress.com/wp-content/uploads/2.jpg</Image>
    </ImageURLs>
    <ExteriorColor>Blue</ExteriorColor>
    <DealerComments>Well maintained, single owner.</DealerComments>
    <StockNumber>12345</StockNumber>
    <TransmissionType>Automatic</TransmissionType>
    <InstalledOptions>Leather Seats; Sunroof</InstalledOptions>
    <DealerID>DEALER123</DealerID>
    <DealerName>Quick Auto Sales</DealerName>
    ...
  </vehicle>
  ...
</inventory>
```

Then the XML is saved:

```php
$xml->asXML($tmpfile);
```

That stores the file in `/wp-content/uploads/cgfe_feed_YYYYMMDD_HHMMSS.xml`.

---

### 4️⃣ Upload via FTP

The plugin reads your FTP settings saved in WordPress options:

```php
$ftp_host = get_option('cgfe_ftp_host');
$ftp_user = get_option('cgfe_ftp_user');
$ftp_pass = get_option('cgfe_ftp_pass');
$ftp_path = get_option('cgfe_ftp_path', '/');
```

Then it connects and uploads:

```php
$conn = ftp_connect($ftp_host, 21, 10);
ftp_login($conn, $ftp_user, $ftp_pass);
ftp_pasv($conn, true);
ftp_put($conn, basename($localfile), $localfile, FTP_BINARY);
ftp_close($conn);
```

So it sends the generated XML file to your external FTP server every day automatically.

---

### 5️⃣ WP-Cron Automation

When you activate the plugin:

```php
register_activation_hook(__FILE__, 'cgfe_activate');
```

This schedules a **daily** job:

```php
wp_schedule_event(time(), 'daily', 'cgfe_daily_event');
```

Every day, WordPress runs:

```php
add_action('cgfe_daily_event', 'cgfe_generate_and_upload_feed');
```

That function repeats the steps above (read → parse → XML → FTP).

---

### 6️⃣ Manual Run (Admin Page)

The plugin adds a menu called **Car Feed Export** in the WordPress admin dashboard.
There’s a **“Generate & Upload Now”** button to manually trigger the process.

---

## 🧩 Summary Flow

| Step | Action                             | Function                     |
| ---- | ---------------------------------- | ---------------------------- |
| 1    | Get all posts from `wp_posts`      | `get_posts()`                |
| 2    | Parse VIN, Make, Model, Year, etc. | regex & helper functions     |
| 3    | Build XML document                 | `SimpleXMLElement`           |
| 4    | Save XML to uploads folder         | `$xml->asXML()`              |
| 5    | Connect & upload to FTP            | `ftp_connect()`, `ftp_put()` |
| 6    | Schedule daily cron                | `wp_schedule_event()`        |
