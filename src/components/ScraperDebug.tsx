
import { useState } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Separator } from "@/components/ui/separator";
import { Badge } from "@/components/ui/badge";
import { ScrollArea } from "@/components/ui/scroll-area";
import { toast } from "sonner";
import { scrapeObituaries, DEFAULT_SCRAPER_CONFIG, scrapePreviousMonthObituaries } from "@/utils/scraperUtils";
import { AlertCircle, CheckCircle, History, Loader2, RefreshCw, Settings, Shield } from "lucide-react";
import { Switch } from "@/components/ui/switch";

const ScraperDebug = () => {
  const [isScrapingNow, setIsScrapingNow] = useState(false);
  const [isHistoricalScraping, setIsHistoricalScraping] = useState(false);
  const [scraperLog, setScraperLog] = useState<Array<{
    timestamp: string;
    type: "info" | "error" | "success" | "warning";
    message: string;
  }>>([]);
  const [scrapedSources, setScrapedSources] = useState<string[]>([]);
  const [connectionErrors, setConnectionErrors] = useState<string[]>([]);
  const [scraperConfig, setScraperConfig] = useState({
    ...DEFAULT_SCRAPER_CONFIG,
  });
  const [useAdaptiveMode, setUseAdaptiveMode] = useState(true);

  const logMessage = (type: "info" | "error" | "success" | "warning", message: string) => {
    const timestamp = new Date().toLocaleTimeString();
    setScraperLog(prev => [...prev, { timestamp, type, message }]);
  };

  const handleTestScrape = async () => {
    setIsScrapingNow(true);
    setScraperLog([]);
    setScrapedSources([]);
    setConnectionErrors([]);
    
    // Log start
    logMessage("info", "Starting enhanced test scrape of obituary sources...");
    logMessage("info", `Using config: ${JSON.stringify({
      regions: scraperConfig.regions,
      maxAge: scraperConfig.maxAge,
      adaptiveMode: useAdaptiveMode,
      retryAttempts: scraperConfig.retryAttempts
    })}`);
    
    try {
      // Set up config with all regions to test comprehensive scraping
      const testConfig = {
        ...scraperConfig,
        adaptiveMode: useAdaptiveMode
      };
      
      // Run the enhanced scraper
      const result = await scrapeObituaries(testConfig);
      
      // Process results
      if (result.success) {
        const foundCount = result.data?.length || 0;
        logMessage("success", `Scraper completed with overall success (found ${foundCount} obituaries)`);
        
        // Track successful regions
        const successfulRegions = scraperConfig.regions.filter(
          region => !result.errors || !result.errors[region]
        );
        setScrapedSources(successfulRegions);
        
        // Track failed regions
        const failedRegions = result.errors ? Object.keys(result.errors) : [];
        setConnectionErrors(failedRegions);
        
        // Log individual errors
        if (result.errors) {
          Object.entries(result.errors).forEach(([region, error]) => {
            logMessage("error", `Region ${region} failed: ${error}`);
          });
        }
        
        toast.success("Scraper test completed", {
          description: `Found ${foundCount} obituaries from ${successfulRegions.length} regions`
        });
      } else {
        logMessage("error", `Scraper failed: ${result.message}`);
        
        // Log specific region errors
        if (result.errors) {
          Object.entries(result.errors).forEach(([region, error]) => {
            logMessage("error", `Region ${region} failed: ${error}`);
            setConnectionErrors(prev => [...prev, region]);
          });
        }
        
        toast.error("Scraper test failed", {
          description: result.message
        });
      }
    } catch (error) {
      logMessage("error", `Test scrape failed with exception: ${error instanceof Error ? error.message : String(error)}`);
      toast.error("Scraper test failed", {
        description: "An unexpected error occurred."
      });
    } finally {
      setIsScrapingNow(false);
    }
  };

  const handleHistoricalScrape = async () => {
    setIsHistoricalScraping(true);
    logMessage("info", "Starting historical data scrape for previous month...");
    
    try {
      // Configure for historical scraping
      const historicalConfig = {
        ...scraperConfig,
        adaptiveMode: useAdaptiveMode,
        retryAttempts: 5, // More retries for historical data
      };
      
      const result = await scrapePreviousMonthObituaries(historicalConfig);
      
      if (result.success) {
        const foundCount = result.data?.length || 0;
        logMessage("success", `Historical scrape completed successfully (found ${foundCount} obituaries)`);
        
        // Record successful/failed regions
        const successfulRegions = scraperConfig.regions.filter(
          region => !result.errors || !result.errors[region]
        );
        const failedRegions = result.errors ? Object.keys(result.errors) : [];
        
        // Log results by region
        successfulRegions.forEach(region => {
          logMessage("success", `Successfully retrieved historical data for ${region}`);
        });
        
        if (result.errors) {
          Object.entries(result.errors).forEach(([region, error]) => {
            logMessage("error", `Failed to retrieve historical data for ${region}: ${error}`);
          });
        }
        
        toast.success("Historical data retrieved", {
          description: `Found ${foundCount} obituaries from previous month`
        });
      } else {
        logMessage("error", `Historical scrape failed: ${result.message}`);
        
        if (result.errors) {
          Object.entries(result.errors).forEach(([region, error]) => {
            logMessage("error", `Region ${region} historical data failed: ${error}`);
          });
        }
        
        toast.error("Historical scrape failed", {
          description: result.message
        });
      }
    } catch (error) {
      logMessage("error", `Historical scrape exception: ${error instanceof Error ? error.message : String(error)}`);
      toast.error("Historical scrape failed", {
        description: "An unexpected error occurred"
      });
    } finally {
      setIsHistoricalScraping(false);
    }
  };

  return (
    <Card className="w-full">
      <CardHeader>
        <CardTitle className="text-xl font-semibold">Enhanced Scraper Diagnostics</CardTitle>
      </CardHeader>
      <CardContent className="space-y-6">
        <div className="space-y-2">
          {/* Scraper config options */}
          <div className="bg-muted/20 p-3 rounded-md mb-4">
            <div className="flex items-center justify-between mb-3">
              <h3 className="text-sm font-medium flex items-center">
                <Settings className="h-4 w-4 mr-2" />
                Scraper Configuration
              </h3>
            </div>
            
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="flex items-center space-x-2">
                <Switch 
                  id="adaptive-mode" 
                  checked={useAdaptiveMode} 
                  onCheckedChange={setUseAdaptiveMode}
                />
                <label htmlFor="adaptive-mode" className="text-sm cursor-pointer flex items-center">
                  <Shield className="h-3.5 w-3.5 mr-1" />
                  Adaptive Structure Detection
                </label>
              </div>
            </div>
          </div>
          
          <div className="flex flex-wrap gap-2 mb-4">
            <Button 
              variant="default" 
              onClick={handleTestScrape}
              disabled={isScrapingNow || isHistoricalScraping}
            >
              {isScrapingNow ? (
                <>
                  <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                  Testing Sources...
                </>
              ) : (
                <>
                  <RefreshCw className="h-4 w-4 mr-2" />
                  Test Scraper Sources
                </>
              )}
            </Button>
            
            <Button 
              variant="outline" 
              onClick={handleHistoricalScrape}
              disabled={isScrapingNow || isHistoricalScraping}
            >
              {isHistoricalScraping ? (
                <>
                  <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                  Retrieving...
                </>
              ) : (
                <>
                  <History className="h-4 w-4 mr-2" />
                  Test Historical Scrape
                </>
              )}
            </Button>
          </div>
          
          {/* Display scraping status */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <Card className="border-border/50">
              <CardHeader className="py-3 px-4">
                <CardTitle className="text-sm font-medium">Successful Connections</CardTitle>
              </CardHeader>
              <CardContent className="py-3 px-4">
                {scrapedSources.length > 0 ? (
                  <div className="flex flex-wrap gap-2">
                    {scrapedSources.map((source) => (
                      <Badge key={source} variant="outline" className="bg-green-500/10 text-green-700 border-green-200">
                        <CheckCircle className="h-3 w-3 mr-1" />
                        {source}
                      </Badge>
                    ))}
                  </div>
                ) : (
                  <p className="text-sm text-muted-foreground">
                    {isScrapingNow || isHistoricalScraping ? "Testing connections..." : "No successful connections yet"}
                  </p>
                )}
              </CardContent>
            </Card>
            
            <Card className="border-border/50">
              <CardHeader className="py-3 px-4">
                <CardTitle className="text-sm font-medium">Failed Connections</CardTitle>
              </CardHeader>
              <CardContent className="py-3 px-4">
                {connectionErrors.length > 0 ? (
                  <div className="flex flex-wrap gap-2">
                    {connectionErrors.map((source) => (
                      <Badge key={source} variant="outline" className="bg-red-500/10 text-red-700 border-red-200">
                        <AlertCircle className="h-3 w-3 mr-1" />
                        {source}
                      </Badge>
                    ))}
                  </div>
                ) : (
                  <p className="text-sm text-muted-foreground">
                    {isScrapingNow || isHistoricalScraping ? "Testing connections..." : "No connection errors yet"}
                  </p>
                )}
              </CardContent>
            </Card>
          </div>
          
          {/* Separator */}
          <Separator className="my-4" />
          
          {/* Debug log */}
          <div>
            <h3 className="text-sm font-medium mb-3">Scraper Log</h3>
            <ScrollArea className="h-[250px] border rounded-md bg-muted/10 p-4">
              {scraperLog.length > 0 ? (
                <div className="space-y-2">
                  {scraperLog.map((log, index) => (
                    <div key={index} className="text-xs">
                      <span className="text-muted-foreground">[{log.timestamp}]</span>{" "}
                      <span className={
                        log.type === "error" ? "text-red-500 font-medium" : 
                        log.type === "success" ? "text-green-500 font-medium" : 
                        log.type === "warning" ? "text-amber-500 font-medium" :
                        "text-foreground"
                      }>
                        {log.message}
                      </span>
                    </div>
                  ))}
                </div>
              ) : (
                <p className="text-sm text-muted-foreground text-center py-4">
                  Run the test to see scraper logs
                </p>
              )}
            </ScrollArea>
          </div>
          
          {/* Help section */}
          <Alert className="mt-6 bg-amber-500/10 border-amber-200">
            <AlertCircle className="h-4 w-4" />
            <AlertTitle>Enhanced Troubleshooting Tips</AlertTitle>
            <AlertDescription>
              <ul className="list-disc pl-5 mt-2 space-y-1 text-sm">
                <li>The scraper now includes adaptive mode to detect website structure changes</li>
                <li>For improved reliability, the system will attempt multiple retries with exponential backoff</li>
                <li>Historical data scraping uses specialized settings for better success with older obituaries</li>
                <li>Verify your server allows connections to external websites (check PHP settings like allow_url_fopen)</li>
                <li>If specific regions consistently fail, check for website changes or regional blocks</li>
              </ul>
            </AlertDescription>
          </Alert>
        </div>
      </CardContent>
    </Card>
  );
};

export default ScraperDebug;
