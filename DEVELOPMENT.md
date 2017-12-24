# EPFL-WS Development
This file list useful notes for development process.

<!-- toc -->

  * [Mock API](#mock-api)
  * [Markdown TOC](#markdown-toc)

<!-- tocstop -->

# Mock API
In case you hit the road and still want to develop, you can use the mock api to
provide the needed data to make it work.

Once started (`npm i; npm start`), the mock api provide json data from actu,
memento, etc...

You can also specify a different port using ENV var, e.g. `export
MOCK_API_PORT=3010; npm start`.

# Markdown TOC
[markdown-toc](https://github.com/jonschlinkert/markdown-toc) is a npm package
that generate the Table Of Content of a Markdown file for you. Just insert the
`<!-- &zwnj;toc -->` tag in the file where you want the TOC to be generate. Then use
`markdown-toc DEVELOPMENT.md -i` to update it.
Note that you should install [markdown-toc](https://github.com/jonschlinkert/markdown-toc)
globally with `npm i markdown-toc -g`.
