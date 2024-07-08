<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Reporte Cajeros</title>
   <style>
      * {
         box-sizing: border-box;
      }

      body {
         margin: 0;
         padding: 0;
         font-family: 'Lato', Tahoma, Verdana, Segoe, sans-serif;
         background-color: #f5f5f5;
      }

      .container {
         max-width: 900px;
         margin: 0 auto;
         background-color: #fff;
         padding: 20px;
      }

      h1 {
         color: #1e0e4b;
         font-size: 38px;
         font-weight: 700;
         text-align: center;
         margin: 20px 0;
      }

      p {
         color: #101112;
         font-size: 16px;
         font-weight: 400;
         text-align: left;
         margin: 10px 0;
      }

      table {
         width: 100%;
         border-collapse: collapse;
         margin: 20px 0;
      }

      th,
      td {
         border: 1px solid #ddd;
         padding: 8px;
         text-align: left;
      }

      th {
         background-color: #f2f2f2;
         color: #333;
      }

      @media print {
         body {
            background-color: #fff;
         }

         .container {
            box-shadow: none;
            margin: 0;
         }

         h1 {
            font-size: 24px;
         }

         table,
         th,
         td {
            border: 1px solid #000;
         }
      }
   </style>
</head>

<body>
   <div class="container">
      <header>
         <img src="images/Diseno_sin_titulo__1_-removebg-preview.png" alt="Logo" style="width: 100%; max-width: 315px; display: block; margin: 0 auto;">
         <h1>Reporte Cajeros</h1>
         <p><strong>Fecha del reporte: 22-22-22</strong></p>
      </header>

      <section>
         <h1>Cajeros Activos</h1>
         <table>
            <thead>
               <tr>
                  <th>Nombre</th>
                  <th>Email</th>
               </tr>
            </thead>
            <tbody>
               @foreach ($activeCajeros as $cajero)
               <tr>
                  <td>{{ $cajero->name }}</td>
                  <td>{{ $cajero->email }}</td>
               </tr>
               @endforeach
            </tbody>
         </table>
      </section>

      <section>
         <h1>Cajeros Inactivos</h1>
         <table>
            <thead>
               <tr>
                  <th>Nombre</th>
                  <th>Email</th>
               </tr>
            </thead>
            <tbody>
               @foreach ($inactiveCajeros as $cajero)
               <tr>
                  <td>{{ $cajero->name }}</td>
                  <td>{{ $cajero->email }}</td>
               </tr>
               @endforeach
            </tbody>
         </table>
      </section>
   </div>
</body>

</html>