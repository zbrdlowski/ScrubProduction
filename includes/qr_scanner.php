<!DOCTYPE html>
<html>
<head>
  <title>Barcode Scanner</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://unpkg.com/@zxing/library@latest"></script>
  <style>
    #videoPreview { width: 100%; max-width: 400px; border: 1px solid #ccc; }
    button { padding: 10px 20px; font-size: 16px; }
  </style>
</head>
<body>
  <h2>Scan Barcode</h2>
  <video id="videoPreview" autoplay></video><br>
  <button onclick="startScanner()">Start Scanner</button>
  <p id="result"></p>

  <script>
    async function startScanner() {
      const codeReader = new ZXing.BrowserBarcodeReader();
      try {
        const result = await codeReader.decodeOnceFromVideoDevice(undefined, 'videoPreview');
        document.getElementById('result').textContent = 'Scanned Code: ' + result.text;
        codeReader.reset();
      } catch (err) {
        console.error('Scan failed:', err);
        document.getElementById('result').textContent = 'Scan failed.';
      }
    }
  </script>
</body>
</html>
