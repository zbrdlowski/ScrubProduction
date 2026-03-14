
<section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
              <h1>

              <script>
function startScanner() {
  const qrReader = new Html5Qrcode("qr-reader");
  qrReader.start(
    { facingMode: "environment" }, // Use rear camera
    {
      fps: 10,
      qrbox: 250
    },
    (decodedText) => {
      document.getElementById("qr-code").value = decodedText;
      qrReader.stop(); // Stop scanning after successful read
      document.getElementById("qr-reader").innerHTML = ""; // Clear preview
    },
    (errorMessage) => {
      // Optional: handle scan errors
      console.warn(errorMessage);
    }
  );
}
</script>
<script>
    // This method will trigger user permissions
Html5Qrcode.getCameras().then(devices => {
  /**
   * devices would be an array of objects of type:
   * { id: "id", label: "label" }
   */
  if (devices && devices.length) {
    var cameraId = devices[0].id;
    // .. use this to start scanning.
  }
}).catch(err => {
  // handle err
});
html5QrCode.start({ deviceId: { exact: cameraId} }, config, qrCodeSuccessCallback);
html5QrCode.stop().then((ignore) => {
  // QR Code scanning is stopped.
}).catch((err) => {
  // Stop failed, handle it.
});
</script>


<form action="your-script.php" method="POST">
  <div class="form-group">
  
    <div class="input-group">
      <input type="text" class="form-control" name="qr-code" id="qr-code" placeholder="order Nr.">
      <div class="input-group-append">
        <button type="button" class="btn btn-outline-primary" onclick="startScanner()">📷 Scan</button>        
      </div>
    </div>
      
    <div class="input-group">
      <input type="text" class="form-control" name="qr-code" id="qr-code" placeholder="Item Code">
      <div class="input-group-append">
        <button type="button" class="btn btn-outline-primary" onclick="startScanner()">📷 Scan</button>
      </div>
    </div>    

    <div class="input-group">
      <input type="text" class="form-control" name="qr-code" id="qr-code" placeholder="Position">
      <div class="input-group-append">
        <button type="button" class="btn btn-outline-primary" onclick="startScanner()">📷 Scan</button>
      </div>
    </div>

      <div class="input-group">
      <input type="text" class="form-control" name="qr-code" id="qr-code" Value="1" placeholder="Quantity">
      <div class="input-group-append">        
      </div>
    </div>

  </div>
</form>

              
 </div>
<!-- /.box-body -->
            </div>
        </div>
<!-- /.card-body -->
        </div>
    </div>
</section>