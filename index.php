<?php
const SUGGESTED_TEXTS = [
  "How much time did each team foil?",
  "Which team achieved the highest speed?",
  "When did each team first reach leg 7? Include the team names."
];
?>

<!DOCTYPE html>
<html>

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>NL2SQL Demo</title>
  <style>
    .is-hidden {
      display: none;
    }
  </style>
  <link rel="stylesheet" href="styles.css" />
  <script>
    function formatJSONasHTML(jsonData) {
      try {
        console.log("parsing", jsonData);
        const data = JSON.parse(jsonData);

        if (!Array.isArray(data) || data.length === 0) {
          throw new Error("Provided JSON is not an array or is empty.");
        }

        let result = "<table>";

        // Display headers
        result += '<tr class="theaderrow">';
        Object.keys(data[0]).forEach((name) => {
          result += `<th class="theader"><div class="theaderdiv">${escapeHTML(
            name
          )}</div></th>`;
        });
        result += "</tr>";

        // Display data
        data.forEach((row) => {
          result += "<tr>";
          Object.values(row).forEach((value) => {
            result += `<td class="tcell">${escapeHTML(value)}</td>`;
          });
          result += "</tr>";
        });

        result += "</table>";
        return result;
      } catch (e) {
        console.error("Invalid JSON data provided.", e);
        return "Invalid JSON data provided.";
      }
    }

    function escapeHTML(str) {
      return str
        .toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
    }

    async function addAssistantMessageToChat({
      title,
      uiType,
      api,
      apiBody,
      parentDOM,
      formattingFunction, //In case we want to format the Json output as a HTML table for example
    }) {
      let systemMessage = null;
      if (uiType === "systemMessage") {
        //Display this as a system message (prerequisite step)
        systemMessage = `
            <div class="chatSystem">
              <div class="chatSystemIconHolder">
                <img
                  src="/images/typingIcon.gif"
                  style="width: 24px; margin-top: 10px"
                  class="chatSystemIcon"
                />
                <div class="chatSystemText">
                  ${title}
                  <div class="content" style="font-weight: 300"></div>
                </div>
              </div>
            </div>
            `;
      }

      //Display this as a chat answer (in a bubble)
      if (uiType === "assistantMessage") {
        systemMessage = `
            <div class="chatAssistant">
              <div class="chatSystemIconHolder">
                <img
                  src="/images/typingIcon.gif"
                  style="width: 24px; margin-top: 10px"
                  class="chatSystemIcon"
                />
              </div>
              <div class="content" style="font-weight: 300"></div>
            </div>
            `;
      }

      parentDOM.innerHTML += systemMessage;
      parentDOM.scrollIntoView(false);
      let iconReference = parentDOM
        .querySelectorAll(".chatSystemIcon")
        .item(parentDOM.querySelectorAll(".chatSystemIcon").length - 1);
      let contentReference = parentDOM
        .querySelectorAll(".content")
        .item(parentDOM.querySelectorAll(".content").length - 1);
      let response = await fetch(api, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: apiBody,
      });
      let jsonResponse = await response.json();
      console.log("jsonResponse.output", jsonResponse.output);
      iconReference.src = "/images/checkedGreenIcon.svg";
      console.log("jsonResponse.output", jsonResponse.output);
      if (formattingFunction !== null) {
        const formattedOutput = formattingFunction(jsonResponse.output);
        contentReference.innerHTML = formattedOutput;
      } else {
        contentReference.innerHTML = jsonResponse.output;
      }
      //data.responseMessage = data.output.replace("\n", "<br>");
      return jsonResponse.output;
    }

    async function handleSendMessage(event) {
      try {
        let currHour = new Date();
        const messageValue = document.querySelector(".inputFieldChat").value;

        //Step 1. Add user message
        const userMsgTemplate = `<div class="chatUser"><img src="/images/user.png" style="width: 24px;" />${messageValue}</div>`;
        let chatBox = document.querySelector(".chatContainerFlexCol");
        chatBox.innerHTML += userMsgTemplate;
        chatBox.scrollIntoView(false);

        //Step 2. Translate the natural language question into a SQL statement
        const sql = await addAssistantMessageToChat({
          title: "Translating question to SQL",
          uiType: "systemMessage",
          api: "llm.php",
          apiBody: JSON.stringify({ prompt: messageValue }),
          parentDOM: chatBox,
          formattingFunction: null,
        });

        //Step 3: Run SQL on database, we get the results as JSON, then convert that to HTML table format
        const databaseJsonResponse = await addAssistantMessageToChat({
          title: "Running SQL",
          uiType: "systemMessage",
          api: "sql.php",
          apiBody: JSON.stringify({ sql: sql.output }),
          parentDOM: chatBox,
          formattingFunction: formatJSONasHTML,
        });

        //Step 4: Call LLM to translate results into natural language
        //Input is a) natural language question, b) the resulting SQL statement and c) the resulting dataset.
        //Output is the natural language answer to the question.
        console.log("databaseJsonResponse", databaseJsonResponse);
        const naturalLanguageAnswer = await addAssistantMessageToChat({
          title: null,
          uiType: "assistantMessage",
          api: "llm.php",
          apiBody: JSON.stringify({
            prompt: messageValue,
            sql: sql,
            dataset: databaseJsonResponse,
          }),
          parentDOM: chatBox,
          formattingFunction: null,
        });
      } catch (error) {
        console.error("Error:", error);
      } finally {
        document.querySelector(".sendMessage").classList.remove("is-loading");
        document.querySelector(".sendMessage").disabled = false;
      }
    }
  </script>
</head>

<body class="bodystyles">
  <div class="header">
    <div class="headertop">
      <img src="logo.png" alt="Logo" />
      <div class="spacer"></div>
      <div class="buttons">
        <div class="action-button red">TICKETS</div>
        <div class="action-button striped">THE DOCK</div>
        <div class="action-button white">
          MENU&nbsp;&nbsp;<svg stroke="currentColor" fill="currentColor" stroke-width="0" class="c-button-menu__icon"
            height="1em" width="1em" viewBox="0 0 24 24">
            <path
              d="M 0 0 L 24 0 L 24 2.18182 L 0 2.18182 L 0 0 Z M 0 10.9091 L 24 10.9091 L 24 13.0909 L 0 13.0909 L 0 10.9091 Z M 0 21.8182 L 24 21.8182 L 24 24 L 0 24 L 0 21.8182 Z">
            </path>
          </svg>
        </div>
      </div>
    </div>
    <div class="headerbottom">
      <div class="breadcrumb">FAN SECTION</div>
      <div class="slash"></div>
      <div class="breadcrumb selected">ASK US</div>
    </div>
  </div>

  <div class="container">
    <div class="chatContainerFlexCol"></div>
  </div>

  <div class="chatInputFooter">
    <div class="suggestionContainer">
      <?php foreach (SUGGESTED_TEXTS as $text): ?>
        <div class="suggestion"
          onclick="document.querySelector('.inputFieldChat').value = '<?php echo addslashes($text); ?>';">
          <?php echo $text; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="chatBox">
      <input class="inputFieldChat" autofocus placeholder="Type your question" value=""
        onkeypress="if(event.keyCode === 13) {handleSendMessage(event);}" />
      <img src="/images/sendIcon.svg" alt="Send" width="20px" class="sendMessage" onclick="handleSendMessage(event)" />
    </div>
  </div>

  <div class="footer">
    <div class="footerImages">
      <img src="logo.png" alt="SailGP Logo Web" />
      &nbsp;
      <img src="world_sailing.svg" style="filter: brightness(0) invert(1)" alt="World Sailing Logo" />
    </div>
  </div>
</body>

</html>