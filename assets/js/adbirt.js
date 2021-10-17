(() => {
    class Adbirt {

        url = "https://adbirt.com/campaigns/verified";
        camp_code = '';
        isCampaignPage = false;
        params = new URLSearchParams;


        // --


        constructor() {

            const currentPageUrl = new URL(window.location.href);
            const params = this.params = currentPageUrl.searchParams;

            this.camp_code = params.get('camp_code');
            this.isCampaignPage = (this.camp_code) ? true : false;

            return this;

        }

        // --


        redirectFormSubmitInit() {

            try {
                if (this.isCampaignPage) {

                    let token = this.camp_code
                    window.localStorage.setItem('redirect_camp_code', token);

                    return token;
                }
                // else {
                //     return window.localStorage.removeItem('redirect_camp_code');
                // }
            } catch (error) {
                return error;
            }

        }

        async redirectFormSubmit() {

            try {
                // if (this.isCampaignPage) {
                //     return;
                // }
                let token = window.localStorage.getItem('redirect_camp_code');

                if (token) {
                    //make request to backend with token to check valid or not 
                    const formUrlParams = new URLSearchParams();
                    formUrlParams.append('campaign_code', token);
                    try {
                        const res = await this.makeApiCall(formUrlParams, 'Redirect Form campaign success !!');
                        console.log(await res.text());
                        window.localStorage.removeItem('redirect_camp_code');

                        return res;
                    } catch (err) {
                        console.log(err);
                        // window.localStorage.removeItem('camp_code');

                        return err;
                    }
                }
            } catch (error) {
                return error;
            }

        }

        // --


        paymentPageInit() {
            return this.redirectFormSubmitInit();
        }

        paymentSuccessPageConsume() {
            return this.redirectFormSubmit();
        }


        // --


        async asyncFormSubmit() {

            // if (this.isCampaignPage) {
            //     return;
            // }

            const formUrlParams = new URLSearchParams
            formUrlParams.append('campaign_code', this.camp_code);

            try {
                const res = await this.makeApiCall(formUrlParams, 'Async Form campaign success !!')
                console.log(res);

                return res;
            } catch (err) {
                console.log(err);

                return err;
            }
        }

        // --


        download(id) {

            // if (!this.isCampaignPage) {
            //     return;
            // }

            const element = document.getElementById(id);

            element.addEventListener('click', async() => {
                const formUrlParams = new URLSearchParams;
                formUrlParams.append('campaign_code', this.camp_code);

                try {
                    const res = await this.makeApiCall(formUrlParams, 'Download campaign success !!');
                    console.log(res);

                    return res;
                } catch (err) {
                    console.log(err);

                    return err;
                }
            });

        }

        clickAction(id) {
            return this.download(id);
        }


        // --


        /**
         * Makes an api call to adbirt backend
         * @param {URLSearchParams} reqBody The request body
         * @param {string} msg An alert message
         * @returns {Promise}
         */
        async makeApiCall(reqBody, msg) {

            try {

                const res = await fetch(this.url, {
                    method: "POST",
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: reqBody
                });

                const json = await res.json();

                // bookmark

                console.log(json);

                return json;
            } catch (error) {
                return error;
            }

        }

    }

    /**
     * @type {Adbirt} {@link Adbirt}
     */
    let AB = null;
    try {
        AB = new Adbirt;

        if (window.AB) {
            window._Adbirt = AB;
        } else {
            window.AB = AB;
        }

        window.adbirtNoConflict = () => {
            return AB;
        }

    } catch (err) {
        console.log(err);
    }
})();