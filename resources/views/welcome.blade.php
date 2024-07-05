<!DOCTYPE html>
<html lang="en">

<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <style>
      body {
         height: 100vh;
         margin: 0;
         display: grid;
         place-items: center;
         background: #161616;
         overflow: hidden;
         color: white;
      }

      .spinner {
         width: 200px;
         height: 200px;
         background: #f5dad8;
         border-radius: 50%;
         overflow: hidden;
         -webkit-animation: spin 6s linear infinite;
         -moz-animation: spin 6s linear infinite;
         -ms-animation: spin 6s linear infinite;
         animation: spin 6s linear infinite;
      }

      img {
         display: block;
         width: 100%;
         -webkit-animation: bounce 0.2s linear infinite alternate, fade-in 2s ease-in-out;
         -moz-animation: bounce 0.2s linear infinite alternate, fade-in 2s ease-in-out;
         -ms-animation: bounce 0.2s linear infinite alternate, fade-in 2s ease-in-out;
         animation: bounce 0.2s linear infinite alternate, fade-in 2s ease-in-out;
      }

      @keyframes spin {
         0% {
            transform: rotate(0turn) scale(1);
         }

         50% {
            transform: rotate(-1turn) scale(1.5);
         }

         100% {
            transform: rotate(-2turn) scale(1);
         }
      }

      @keyframes bounce {
         to {
            translate: 0 20px;
         }
      }

      @keyframes fade-in {
         from {
            opacity: 0;
         }

         to {
            opacity: 1;
         }
      }
   </style>
</head>

<body>
   <div class="spinner">
      <img src="https://codetheworld.io/wp-content/uploads/2024/05/pedro.png" alt="Spinner Image">
   </div>
</body>

</html>