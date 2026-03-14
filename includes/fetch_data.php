<body onload = "table();">
    <script type="text/javascript">
      function table(){
        const xhttp = new XMLHttpRequest();
        xhttp.onload = function(){
          document.getElementById("table").innerHTML = this.responseText;
        }
        xhttp.open("GET", "includes/system.php");
        xhttp.send();
      }

      setInterval(function(){
        table();
      }, 1);
    </script>
    <div id="table">
    </div>
  </body>