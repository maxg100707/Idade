function verificar() {
  var data = new Date();
  var ano = data.getFullYear();
  var fano = document.getElementById("txtano");
  var res = document.getElementById("res");

  if (fano.value.lenght == 0 || fano.value > ano) {
    window.alert("Ano inavlido");
  } else {
    var fsex = document.getElementsByName("radsex");
    var idade = ano - Number(fano.value);
    var gen = "";
    var img = document.createElement("img");
    img.setAttribute("id", "foto");
    img.style.borderRadius = "300px";
    img.style.height = "350px";
    img.style.width = "350px";

    if (fsex[0].checked) {
      gen = "Homem";

      if (idade >= 0 && idade < 12) {
        //crianca
        img.setAttribute("src", "/idade/img/criancahomem.jpg");
      } else if (idade >= 12 && idade < 21) {
        //jovem
        img.setAttribute("src", "/idade/img/jovemhomem.jpg");
      } else if (idade >= 21 && idade < 65) {
        //adulto
        img.setAttribute("src", "/idade/img/adultohomem.jpg");
      } else {
        //idoso
        img.setAttribute("src", "/idade/img/idosohomem.jpg");
      }
    } else if (fsex[1].checked) {
      gen = "Mulher";
      if (idade >= 0 && idade < 12) {
        //crianca
        img.setAttribute("src", "/idade/img/criancamulher.jpg");
      } else if (idade >= 12 && idade < 21) {
        //jovem
        img.setAttribute("src", "/idade/img/jovemmulher.jpg");
      } else if (idade >= 21 && idade < 65) {
        //adulto
        img.setAttribute("src", "/idade/img/adultomulher.jpg");
      } else {
        //idoso
        img.setAttribute("src", "/idade/img/idosomulher.jpg");
      }
    }
    res.style.textAlign = "center";
    res.innerHTML = "É " + gen + " de " + idade + " anos";
    res.appendChild(img);
  }
}
/*
        _             _
       / \           / \
      /   \         /   \
     /     \_______/     \
    /  ___           ___  \
   |  / ()\         / ()\  |
   |  \___/         \___/  |
   |           ___         |
   \          |___|        /
    \           |         /
     \      \___|___/    /
      \_               _/
        \_____________/  
           MIAW MIAW


instagram : https://www.instagram.com/maxg1007/
portifolio : https://maxg100707.github.io/Portifolio/

*/
