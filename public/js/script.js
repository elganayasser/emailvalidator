function removeDiacriticsAndSpecialChars(str) {
    return str
      .normalize("NFD") // Remove diacritics
      .replace(/[\u0300-\u036f]/g, '')
      .replace(/[^\w\s-]/gi, ''); // Remove other special characters except hyphen
  }
  
     const searchInput = document.getElementById('search-input');
    const suggestionsList = document.getElementById('suggestions-list');
    const searchImage = document.getElementById('Companylogo');
  
    
  async function fetchSuggestionsData() {
    try {
      const response = await fetch('/suggestions');
      if (!response.ok) {
        throw new Error('Network response was not ok');
      }
      const data = await response.json();
      if (!data || !Array.isArray(data.results)) {
        throw new Error('Invalid data format. Expected an object with a "results" array.');
      }
      return data.results.map(item => ({
        nomEntreprise: item.nomEntreprise,
        logoImage: item.logoImage // Assuming this property exists in the API response
      }));
    } catch (error) {
      console.error('Error fetching suggestions data:', error);
      return [];
    }
  }
  
  async function showSuggestions(event) {
    const searchTerm = event.target.value.toLowerCase();
    const suggestionsData = await fetchSuggestionsData();
    const filteredSuggestions = suggestionsData.filter(item =>
      String(item.nomEntreprise).toLowerCase().startsWith(searchTerm)
    );
  
    if (searchTerm === '') {
      suggestionsList.style.display = 'none';
      searchImage.style.display = 'none';
      searchInput.style.paddingLeft = null;
    } else if (filteredSuggestions.length === 0) {
      suggestionsList.style.display = 'none';
      searchImage.style.display = 'none';
      searchInput.style.paddingLeft = null;
    } else {
      suggestionsList.style.display = 'block';
      suggestionsList.innerHTML = '';
  
      filteredSuggestions.forEach(suggestionData => {
        const li = document.createElement('li');
  
        // Create an image element for the company logo
        const logoImg = document.createElement('img');
        logoImg.src = suggestionData.logoImage;
        logoImg.alt = 'Company Logo';
        logoImg.className = 'logo'; // Add any necessary classes or styles
  
        // Create a span element for the company name
        const companyNameSpan = document.createElement('span');
        companyNameSpan.textContent = suggestionData.nomEntreprise;
  
        // Append the logo and company name to the list item
        li.appendChild(logoImg);
        li.appendChild(companyNameSpan);
  
        // Add a click event listener to populate the search input with the selected suggestion
        li.addEventListener('click', () => {
          searchInput.value = suggestionData.nomEntreprise; // Set the company name
          suggestionsList.style.display = 'none';
          searchInput.style.paddingLeft = '30px';
          searchImage.style.display = 'inline';
          searchImage.src = suggestionData.logoImage;
  
  
        });
  
        // Append the list item to the suggestions list
        suggestionsList.appendChild(li);
      });
    }
  }
  
    if(searchInput){
      searchInput.addEventListener('input', showSuggestions);
    }
   
    let resultHTML = '';
    let companyNametoSent = '';
    let successRate = '';
    let emailList = '';
  function generateEmailsWithSpecialChars(companyName, firstName, lastName ) {
    //const url = `/catchmyemail?companyName=${encodeURIComponent(companyName)}}`;
    const url = '/catchmyemail?companyName=' + companyName.toLowerCase();

    companyNametoSent = companyName;
    //console.log(url);
    
  
    return fetch(url)
      .then(function (response) {
        if (response.ok) {
          return response.json(); // Parse the response as JSON
        }
        throw new Error('Network response was not OK.');
      })
      .then(function (data) {
        console.log('Response:', data);
  
        if (data.results && data.results.length > 0) {
          const emailFormat = data.results[0].emailformat;

          emailList = data.results[0].email_list;
  
          if (emailFormat) {
            const normalizedFirstName = removeDiacriticsAndSpecialChars(firstName.toLowerCase().replace(/\s+/g, ''));
            const normalizedLastName = removeDiacriticsAndSpecialChars(lastName.toLowerCase().replace(/\s+/g, ''));
  
            const emailWithSpecialChars = emailFormat
              .replace("[p]", normalizedFirstName.charAt(0))
              .replace("[no]", normalizedLastName.substr(0, 2))
              .replace("[n]", normalizedLastName.charAt(0))
              .replace("[prenom]", normalizedFirstName)
              .replace("[nom]", normalizedLastName)
              .replace(/\s/g, ''); // Remove any spaces from the email
  
          
  
            if(emailFormat.includes('-')){
              
              var emailWithoutSpecialChars = emailWithSpecialChars;
            }else{
              
              var emailWithoutSpecialChars = emailWithSpecialChars.replace(/-/g, '');
            }
            //const emailWithoutSpecialChars = emailWithSpecialChars.replace(/-/g, ''); // Remove "-" character from the email
  
            return {
              emailWithSpecialChars: firstName.includes('-') || lastName.includes('-') ? emailWithSpecialChars : null,
              emailWithoutSpecialChars,
            };
          } else {
            throw new Error('Invalid email format.');
          }
        } else {
          throw new Error('No company found.');
        }
      });
  }
  function generateAndDisplayEmail() {
    const companyName = document.getElementById("search-input").value;
    const firstName = document.getElementById("firstName").value.toLowerCase();
    const lastName = document.getElementById("lastName").value.toLowerCase();
  
    const companyNameInput = document.getElementById("search-input");
    const firstNameInput = document.getElementById("firstName");
    const lastNameInput = document.getElementById("lastName");
  
    const logoCompany = document.getElementById("Companylogo").src;
    
  
    // Reset the border and text color of all inputs
    companyNameInput.style.border = '1px solid #ccc';
    firstNameInput.style.border = '1px solid #ccc';
    lastNameInput.style.border = '1px solid #ccc';
    companyNameInput.style.color = '#000';
    firstNameInput.style.color = '#000';
    lastNameInput.style.color = '#000';
    //emailResult.style.color = ' #01a101';
    resultsdiv.style.display= "flex";

  
    if (!companyName) {
      document.getElementById("emailResult").textContent = "Please enter a company name.";
      companyNameInput.style.border = '1px solid #AE2118';
      companyNameInput.style.color = '#AE2118';
      emailResult.style.color = '#AE2118';
      user.style.display='none';
      copytext.style.display='none';
      return;
    }
  
    if (!firstName || !lastName) {
      
      if (!firstName) {
      document.getElementById("emailResult").textContent = "First name is missing";
        firstNameInput.style.border = '1px solid #AE2118';
        firstNameInput.style.color = '#AE2118';
        emailResult.style.color = '#AE2118';
        user.style.display='none';
      copytext.style.display='none';
        
      }
      if (!lastName) {
      document.getElementById("emailResult").textContent = "Last name is missing";
        lastNameInput.style.border = '1px solid #AE2118';
        lastNameInput.style.color = '#AE2118';
        emailResult.style.color = '#AE2118';
        user.style.display='none';
      copytext.style.display='none';
        
      }
      return;
    }

    function checkRecipient(emailWithoutSpecialChars) {
      const url = '/check-recipient';
      const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  
      return fetch(url, {
          method: 'POST',  // Change the method to POST
          headers: {
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': csrfToken, // Include the CSRF token
          },
         
          body: JSON.stringify({
              emailWithoutSpecialChars: emailWithoutSpecialChars,
          }),
      })
      .then(function (response) {
          if (response.ok) {
              return response.json(); // Parse the response as JSON
          }
          throw new Error('Network response was not OK.');
      });
      
  }


  

    generateEmailsWithSpecialChars(companyName, firstName, lastName)
      .then(function (emails) {
      
  
        if (emails.emailWithSpecialChars && emails.emailWithoutSpecialChars) {
          if (emails.emailWithSpecialChars === emails.emailWithoutSpecialChars) {
            resultHTML += emails.emailWithoutSpecialChars;
          } else {
            resultHTML += `${emails.emailWithSpecialChars}<br> OR <br>${emails.emailWithoutSpecialChars}`;
          }
        } else if (emails.emailWithSpecialChars) {
          resultHTML += emails.emailWithSpecialChars;
        } else {
          resultHTML += emails.emailWithoutSpecialChars;
        }
        
        fakeEmail = 'PleasePayFortheReveal@mail.com';
        document.getElementById("emailResult").innerHTML = fakeEmail;
         document.getElementById("user").src = document.getElementById("Companylogo").src;
         document.getElementById("user").style.width = "50px";
         document.getElementById("user").style.height = "50px";

        checkRecipient(emails.emailWithoutSpecialChars)
        .then(function (recipientCheckResult) {
        

            // Display the result in HTML
            const resultElement = document.getElementById('recipientResult');

            console.log("statuuus : " + recipientCheckResult.response.status  );
  
            if (recipientCheckResult.response.status === 'AcceptAll') {
              // Display "Email is risky" if the 'status' attribute is 'risky'
              resultElement.textContent = `70%`;
              document.getElementById("emailResult").style.color =  '#D0D01C';
              resultElement.style.color = '#D0D01C';

          } else if (recipientCheckResult.response.status === 'nonvalide') {
              // Display "Email doesn't exist" if the "message" attribute is present
              resultElement.textContent = `3%`;
              document.getElementById("emailResult").style.color = '#E9290E';
              resultElement.style.color = '#E9290E';
          } else {
              // Display "Email exists" if none of the above conditions are met
              resultElement.textContent = `90%`;
           document.getElementById("emailResult").style.color = '#079625';
           resultElement.style.color = '#079625';
          }

          successRate = resultElement.textContent;  
         
            //resultElement.textContent = `Recipient Check Result: ${JSON.stringify(recipientCheckResult.response)}`;


            //the true Email 
            //send the emil to fron end after payment 
            
  
        })
        .catch(function (error) {
            console.error('Error checking recipient:', error);
        });

        
      })
      .catch(function (error) {
        document.getElementById("emailResult").textContent = "Error: " + error.message;
        emailResult.style.color = '#AE2118';
        user.style.display='none';
        copytext.style.display='none';
      });
  }
  // ]]>

    var csvFilelineNbr = 0;
  if(document.getElementById('revealEmailBtn')){


    document.getElementById('revealEmailBtn').addEventListener('click', function() {
      // retreive the email  resultHTML
  
      const email = resultHTML;
      const company = companyNametoSent;
      const rate = successRate;
      const emailListtoSend = emailList;
      
      window.location.href = `/initiate-checkout?email=${encodeURIComponent(email)}&company=${encodeURIComponent(company)}&rate=${encodeURIComponent(rate)}&emailList=${encodeURIComponent(emailListtoSend)}`;


});

}

if(document.getElementById('revealEmailBtnbulk')){

document.getElementById('revealEmailBtnbulk').addEventListener('click', function() {
  // retreive the email  resultHTML

  
  
  window.location.href = `/initiate-checkoutbulk?csvFilelineNbr=${encodeURIComponent(csvFilelineNbr)}`;


});

}



if(document.getElementById("cp_btn")){
  document.getElementById("cp_btn").addEventListener("click", copy_email);
}
  
  
  function copy_email() {
      var copyText = document.getElementById("emailResult");
      var textArea = document.createElement("textarea");
      textArea.value = copyText.textContent;
      document.body.appendChild(textArea);
      textArea.select();
      document.execCommand("Copy");
      textArea.remove();
       var tooltip = document.getElementById("myTooltip");
    tooltip.innerHTML = "Copied ! ";
  }



  function checkBulkRecipients(csvFile) {
    const url = '/check-bulkrecipient';
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    const formData = new FormData();
    formData.append('csvFile', csvFile);

    fetch(url, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': csrfToken,
        },
        body: formData,
    })

    .catch(error => {
        console.error('Error checking bulk recipients:', error);
    });
}


function startTask() {
  // Make an AJAX request to start the task
  fetch('/get-progress')
      .then(response => {
          if (!response.ok) {
              throw new Error('Network response was not ok');
          }
          return response.json();
      })
      .then(data => {
          console.log(data);
          // Start listening for events
          const progressChannel = new EventSource('/get-progress');
          progressChannel.onmessage = function(event) {
              const eventData = JSON.parse(event.data);
              updateProgressBar(eventData.progress);
          };
      })
      .catch(error => {
          console.error('There was a problem with the fetch operation:', error);
      });
}

function updateProgressBar(progress) {
  // Update your progress bar element with the new progress value
  document.getElementById('progress-bar').style.width = progress + '%';
}











  // ]]>