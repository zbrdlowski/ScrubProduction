<div>
        <h1>Scrub Order Management</h1>
        <table id="example2" width="100%" class="table table-bordered">
            <thead>
                <tr style="background-color:grey;color:white;">
                <th>Date</th>
                <th>Order Source</th>
                <th>GFP</th>
                <th>SKU</th>
                <th>Order No.</th>
                <th>Customer</th>
                <th>Country</th>
                <th>Courier</th>
                <th>Order Status</th>
                <th><center>Product Name</center></th>                
                <th>Invoice</th>
                <th>Tracking</th>
                <th>Bike</th>
                <th>Model</th>
                <th>Year</th>
                    
                </tr>
            </thead>
            <tbody>
            </tbody>
        </table>
    </div>
    <!-- We will use SSE in JS to get event from server -->
    <!-- for best performance in realtime fetching -->
     <style>
      #GFP { Background-color:#a1cbf0 }
      #P { Background-color:SILVER; }
      #G { Background-color:#deb887; }
      #S { Background-color:grey; color:white; }
      #TFP { Background-color:#0fc7ae; color:white; }
     </style>
<script>
    var source = new EventSource("fetch.php");
    source.onmessage = function (event) {
        var arrayData = JSON.parse(event.data);
        var dataContainer = document.querySelector('tbody')
        dataContainer.innerHTML = `<tr style="background-color: silver;">`
          arrayData.forEach(e => {
            dataContainer.innerHTML += `                
                    <td id="${e.gfp}">${e.niceDate}</td>
                    <td id="${e.gfp}"><center>${e.order_type}</center></td>
                    <td id="${e.gfp}">${e.gfp}</td>
                    <td id="${e.gfp}">${e.sku}</td>
                    <td id="${e.gfp}"><a href="index.php">${e.order_nr}</a></td>
                    <td id="${e.gfp}">${e.customer}</td>
                    <td id="${e.gfp}">${e.country}</td>
                    <td id="${e.gfp}">${e.courier}</td>
                    <td id="${e.gfp}">${e.status}</td>
                    <td id="${e.gfp}">${e.product_name}</td>
                    <td id="${e.gfp}">${e.invoice}</td>
                    <td id="${e.gfp}">${e.tracking}</td>
                    <td id="${e.gfp}">${e.bike}</td>
                    <td id="${e.gfp}">${e.model}</td>
                    <td id="${e.gfp}">${e.year}</td>`;
        dataContainer.innerHTML += `</tr>`;
        });
    }
</script>
            </div>