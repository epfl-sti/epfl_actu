var express = require('express'),
    json = require('express-json'),
    mock_memento_fr_jahia = require("./mock_memento_fr_jahia"),
    mock_actus_fr_jahia = require("./mock_actus_fr_jahia"),
    mock_actus_fr_v1 = require("./mock_actus_fr_v1");

var servingPort = process.env.MOCK_API_PORT || 3010;
var app = express()
  .use(json())
  .use(function (req, res) {
    console.log(req.url);
    if (req.url.startsWith("/search?p=")) {
      res.type("application/xml")
        .sendFile(__dirname + "/infoscience.xml");
    } else if (req.url.match(/\/jahia\/memento/)) {
      res.json(mock_memento_fr_jahia);
    } else if (req.url.match(/\/jahia\//)) {
      res.json(mock_actus_fr_jahia);
    } else if (req.url.match(/\/api\/v1\/news/)) {
      res.json(mock_actus_fr_v1);
    } else if (req.url.match(/^\/[1-9][0-9]{5}/)) {
      res.type("text/html").sendFile(__dirname + "/people.html");
    } else if (req.url.startsWith("/cgi-bin/people/showcv")) {
      res.type("text/html").sendFile(__dirname + "/people-admin.html");
    } else if (req.url.startsWith("/ubrowse.action?acro=")) {
      res.type("text/html").sendFile(__dirname + "/EPFL-unit.html");
    } else if (req.url.startsWith("/listes?sciper=")) {
      res.type("text/html").sendFile(__dirname + "/cadi-listes.html");
    } else {
      res.status(404).type("text/html")
        .send(`<body>
        <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/2/2f/Peugeot_404_Familiale_1968.jpg/351px-Peugeot_404_Familiale_1968.jpg"></img>
        <ul>
              <li><a href="/243371">People</a></li>
              <li><a href="/cgi-bin/people/showcv">People administrative details</a></li>
              <li><a href="/listes?sciper=243371">CADI listes</a></li>
              <li><a href="/ubrowse.action?acro=ASL">Unit</a></li>
              <li><a href="/search?p=infoscience">Infoscience</a></li>
              <li><a href="/jahia/memento">Memento (Jahia API)</a></li>
              <li><a href="/jahia/channels">Actu (Jahia API)</a></li>
              <li><a href="/api/v1/news">Actu (v1 API)</a></li>
        </ul>
    </body>`);
    }
  })
  .listen(servingPort, function() {
      console.log("Listening on port " + servingPort);
});
