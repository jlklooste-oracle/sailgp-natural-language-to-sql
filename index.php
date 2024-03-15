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
    var WELCOME_MESSAGE = "Welcome to a demonstration of natural language to SQL on MySQL! I'm here to help you explore what happened during the SailGP race. You can ask about metrics such as the times teams reach specific legs of the race, boat speeds, angles relative to the wind, and much more. I will translate your question to SQL and handle the logic of querying the database and interpreting the results. What is your question?"
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

    function toggleContentVisibility(element) {
      // Find the parent of the clicked toggleDiv
      const parent = element.parentNode;

      // Within this parent, find the .content div
      const contentDiv = parent.querySelector(".content");

      if (element.textContent === "Hide") {
        contentDiv.classList.add("hidden");
        element.textContent = "Show";
      } else {
        contentDiv.classList.remove("hidden");
        element.textContent = "Hide";
      }
    }

    async function addMessageToChat({
      title,
      initialContent,
      uiType,
      api,
      apiBody,
      parentDOM,
      formattingFunction, //In case we want to format the Json output as a HTML table for example
    }) {
      let result = null;
      let html = null;
      //Display this as a chat question (user question in a bubble)
      if (uiType === "userMessage") {
        html = `
            <div class="chatUser">
              ${initialContent ? initialContent : ''}</div>
            </div>
            `;
      }

      if (uiType === "assistantMessage") {
        html = `
      <div class="chatAssistant">
        ${api !== null ? `
          <div class="chatSystemIconHolder">
            <img
              src="/images/typingIcon.gif"
              style="width: 24px;"
              class="chatSystemIcon"
            />
          </div>` : ''}
        <div class="content" style="font-weight: 300">${initialContent ? initialContent : ''}</div>
      </div>
      `;
      }

      //Display this as a system message (prerequisite step before we get response)
      if (uiType === "systemMessage") {
        html = `
            <div class="chatSystem">
              <div class="chatSystemIconHolder">
                <img
                  src="/images/typingIcon.gif"
                  style="width: 24px;"
                  class="chatSystemIcon"
                />
                </div>
                 <div class="chatSystemText">
                  ${title}
                  <div class="content hidden" style="font-weight: 300">${initialContent ? initialContent : ''}</div>
                  <div class="toggleDiv hidden" onclick="toggleContentVisibility(this)">Show</div>
                </div>
            </div>
            `;
      }

      parentDOM.innerHTML += html;
      parentDOM.scrollIntoView(false);
      await new Promise(resolve => setTimeout(resolve, 10));
      console.log("a")
      if (api !== null) {
        console.log("b")
        let iconReference = parentDOM
          .querySelectorAll(".chatSystemIcon")
          .item(parentDOM.querySelectorAll(".chatSystemIcon").length - 1);
        let contentReference = parentDOM
          .querySelectorAll(".content")
          .item(parentDOM.querySelectorAll(".content").length - 1);
        let visibilityToggler = parentDOM
          .querySelectorAll(".toggleDiv")
          .item(parentDOM.querySelectorAll(".toggleDiv").length - 1);
        let response = await fetch(api, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
          },
          body: apiBody,
        });
        console.log("c")
        let jsonResponse = await response.json();
        console.log("jsonResponse.output", jsonResponse.output);
        if (jsonResponse.error) {
          iconReference.src = "/images/errorIcon.png";
        } else {
          iconReference.src = "/images/checkedGreenIcon.svg";
        }
        console.log("jsonResponse.output", jsonResponse.output);
        if (formattingFunction !== null) {
          const formattedOutput = formattingFunction(jsonResponse.output);
          contentReference.innerHTML = formattedOutput;
        } else {
          contentReference.innerHTML = jsonResponse.output;
        }
        visibilityToggler.classList.remove("hidden"); //show the hide button
        await new Promise(resolve => setTimeout(resolve, 1000));
        result = jsonResponse
      }
      console.log("returning")
      //data.responseMessage = data.output.replace("\n", "<br>");
      return result
    }

    async function handleSendMessage(event) {
      try {
        let currHour = new Date();
        const messageValue = document.querySelector(".inputFieldChat").value;

        //Step 1. Add user message
        let chatBox = document.querySelector(".chatContainerFlexCol");
        await addMessageToChat({
          title: null,
          initialContent: messageValue,
          uiType: "userMessage",
          api: null,
          apiBody: null,
          parentDOM: chatBox,
          formattingFunction: null,
        });

        //Step 2. Translate the natural language question into a SQL statement
        let { error, sql } = await addMessageToChat({
          title: "Translating question to SQL",
          initialContent: null,
          uiType: "systemMessage",
          api: "llm.php",
          apiBody: JSON.stringify({ prompt: messageValue }),
          parentDOM: chatBox,
          formattingFunction: null,
        });

        console.log("before step 3, error", error)

        //Step 3: Run SQL on database, we get the results as JSON, then convert that to HTML table format
        let databaseJsonResponse = null
        if (!error) {
          console.log("step 3, error", error)
          error = null
          { error, databaseJsonResponse } = await addMessageToChat({
            title: "Running SQL",
            initialContent: null,
            uiType: "systemMessage",
            api: "sql.php",
            apiBody: JSON.stringify({ sql: sql.output }),
            parentDOM: chatBox,
            formattingFunction: formatJSONasHTML,
          });
        }

        console.log("before step 4, error", error)

        //Step 4: Call LLM to translate results into natural language
        //Input is a) natural language question, b) the resulting SQL statement and c) the resulting dataset.
        //Output is the natural language answer to the question.
        console.log("databaseJsonResponse", databaseJsonResponse);
        let naturalLanguageAnswer = null
        if (!error) {
          error = null
          { error, naturalLanguageAnswer } = await addMessageToChat({
            title: null,
            initialContent: null,
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
        }
      } catch (error) {
        console.error("Error:", error);
      } finally {
        document.querySelector(".sendMessage").classList.remove("is-loading");
        document.querySelector(".sendMessage").disabled = false;
      }
    }

    window.onload = function () {
      //Add a welcome message onload
      console.log("adding message")
      let chatBox = document.querySelector(".chatContainerFlexCol");
      addMessageToChat({
        title: null,
        initialContent: WELCOME_MESSAGE, // make sure messageValue is defined somewhere
        uiType: "assistantMessage",
        api: null,
        apiBody: null,
        parentDOM: chatBox,
        formattingFunction: null,
      })
    };
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
    <div class="chatContainerFlexCol">
      <!--This is where the chat bubbles are added-->
    </div>
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
      <img src="/images/world_sailing.svg" style="filter: brightness(0) invert(1)" alt="World Sailing Logo" />
    </div>
  </div>
</body>

</html>