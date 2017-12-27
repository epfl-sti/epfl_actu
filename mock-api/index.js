var express = require('express'),
    json = require('express-json'),
    mock_memento_fr_jahia = require("./mock_memento_fr_jahia"),
    mock_actus_fr_jahia = require("./mock_actus_fr_jahia"),
    mock_actus_fr_v1 = require("./mock_actus_fr_v1");

var servingPort = process.env.MOCK_API_PORT || 3010;
var app = express()
  .use(json())
  .use(function (req, res) {
    if (req.url.match(/\/jahia\/memento/)) {
      res.json(mock_memento_fr_jahia);
    } else if (req.url.match(/\/jahia\//)) {
      res.json(mock_actus_fr_jahia);
    } else if (req.url.match(/\/api\/v1\/news/)) {
      res.json(mock_actus_fr_v1);
      return;
    } else {
      res.status(404).type("text/html")
        .send("<img src=\"https://upload.wikimedia.org/wikipedia/commons/thumb/2/2f/Peugeot_404_Familiale_1968.jpg/351px-Peugeot_404_Familiale_1968.jpg\">");
    }
  })
  .listen(servingPort, function() {
      console.log("Listening on port " + servingPort);
});
