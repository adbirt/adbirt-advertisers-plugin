(() => {
    class Adbirt {

        url = "https://adbirt.com/campaigns/verified";
        camp_code = '';
        isCampaignPage = false;

        constructor() {

            const url = new URL(window.location.href)
            const params = url.searchParams;

            this.camp_code = params.get('camp_code');
            this.isCampaignPage = (this.camp_code) ? true : false;

            return this;

        }

        paymentPageInit() {
            return this.redirectFormSubmitInit();
        }

        paymentSuccessPageConsume() {
            return this.redirectFormSubmit();
        }

        redirectFormSubmitInit() {
            if (this.isCampaignPage) {
                const isRedirectFormCampaign = true;
                let token = window.localStorage.getItem('camp_code');
                if (isRedirectFormCampaign) {
                    //make request to backedn at get token
                    let token = this.camp_code
                    return window.localStorage.setItem('camp_code', token)
                }
            } else
                return window.localStorage.removeItem('camp_code')
        }

        async redirectFormSubmit() {
            if (this.isCampaignPage)
                return;
            let token = window.localStorage.getItem('camp_code');
            if (token) {
                //make request to backend with token to check valid or not 
                const formData = new FormData
                formData.append('campaign_code', token)
                try {
                    const res = await this.makeApiCall(formData, 'Redirect Form campaign success !!')
                    console.log(res)
                    window.localStorage.removeItem('camp_code');

                    return res;
                } catch (err) {
                    console.log(err)
                    window.localStorage.removeItem('camp_code');

                    return res;
                }
            }
        }

        async asyncFormSubmit() {
            if (this.isCampaignPage)
                return;
            const formData = new FormData
            formData.append('campaign_code', this.camp_code)
            try {
                const res = await this.makeApiCall(formData, 'Async Form campaign success !!')
                console.log(res);

                return res;
            } catch (err) {
                console.log(err);

                return res;
            }
        }

        download(id) {
            if (!this.isCampaignPage)
                return;
            const element = document.getElementById(id)
            element.addEventListener('click', async() => {
                const formData = new FormData
                formData.append('campaign_code', this.camp_code)
                try {
                    const res = await this.makeApiCall(formData, 'Download campaign success !!')
                    console.log(res)
                } catch (err) {
                    console.log(err)
                        // err.text()
                        // .then(data=>{
                        //     console.log(data)
                        // })
                        // .catch(err=>{
                        //     console.log(err)
                        // })
                }
            })
        }

        clickAction(id) {
            return this.download(id);
        }

        makeApiCall(reqBody, msg) {
            return new Promise((resolve, reject) => {
                fetch(this.url, {
                        method: "POST",
                        body: reqBody
                    })
                    .then(res => {
                        res.text()
                            .then(data => {
                                // if(res.status !== 200 && res.status !==201){
                                reject(data)
                                    // }else{
                                    //     resolve(data)
                                    //     console.log(msg)
                                    // }
                            })
                            .catch(err => {
                                reject(err)
                            })
                    })
                    .catch(err => {
                        err.json()
                            .then(data => {
                                reject(data)
                            })
                            .catch(() => {
                                err.text()
                                    .then(data => {
                                        reject(data)
                                    })
                            })
                    })
            })

        }

    }

    /**
     * @type {Adbirt} {@link Adbirt}
     */
    let AB = null
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